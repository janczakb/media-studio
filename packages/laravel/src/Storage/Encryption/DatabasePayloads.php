<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Encryption\Encrypter;
use SensitiveParameter;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Explicit field encryption: leaves relational/filter columns available to indexed queries. */
final class DatabasePayloads
{
    public const FIELDS = [
        'fms_ai_messages' => ['content'],
        'fms_ai_runs' => ['context', 'usage', 'error', 'partial_text'],
        'fms_ai_events' => ['data'],
        'fms_ai_actions' => ['result', 'undo'],
        'fms_ai_tool_receipts' => ['result'],
        'fms_ai_conversations' => ['encrypted_title'],
        'fms_comments' => ['body', 'document', 'mentions'],
        'fms_notes' => ['document'],
        'fms_entries' => ['note_excerpt'],
    ];

    private ?ContentKeyring $keys = null;

    private array $encrypters = [];

    private array $indexKeys = [];

    public function enabled(): bool
    {
        $enabled = config('media-studio.encryption.database.enabled', false) === true;
        if ($enabled && ! in_array(app(EncryptionProfiles::class)->mode(), ['application', 'hybrid'], true)) {
            throw new EncryptionFailure('Database payload encryption requires application or hybrid mode.');
        }

        return $enabled;
    }

    public function keys(): ContentKeyring
    {
        return $this->keys ??= new ContentKeyring((array) config('media-studio.encryption.keys', []), (string) config('media-studio.encryption.active_key', ''));
    }

    public function seal(string $table, string|int $identity, string $column, #[SensitiveParameter] ?string $value): ?string
    {
        if ($value === null || ! $this->enabled()) {
            return $value;
        }
        $context = $this->context($table, $identity, $column);
        if (strlen($value) > 4194304) {
            throw new EncryptionFailure('Database payload exceeds its encryption limit.');
        }
        $id = $this->keys()->active;
        $cipher = $this->encrypter($id)->encryptString(pack('N', strlen($context)).$context.$value);

        // A valid JSON envelope works for both JSON and text columns on MySQL/Postgres/SQLite.
        return json_encode(['__fms_encrypted_v1' => $id.':'.$cipher], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function open(string $table, string|int $identity, string $column, #[SensitiveParameter] ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $context = $this->context($table, $identity, $column);
        $envelope = $this->envelope($value);
        if ($envelope === null) {
            if ($this->enabled() && config('media-studio.encryption.database.allow_plaintext_reads', false) !== true) {
                throw new EncryptionFailure('Unencrypted database payload encountered. Complete the encryption migration.');
            }

            return $value;
        }
        try {
            [$id, $cipher] = explode(':', $envelope, 2);
            $plain = $this->encrypter($id)->decryptString($cipher);
            if (strlen($plain) < 4) {
                throw new EncryptionFailure('Invalid database ciphertext.');
            }
            $length = unpack('Nlength', substr($plain, 0, 4))['length'];
            if ($length !== strlen($context) || ! hash_equals($context, substr($plain, 4, $length))) {
                throw new EncryptionFailure('Database ciphertext belongs to another record or field.');
            }

            return substr($plain, 4 + $length);
        } catch (\Throwable $error) {
            // Do not attach raw ciphertext, keys or plaintext as exception context.
            throw new EncryptionFailure('Database payload authentication failed.');
        }
    }

    public function protect(string $table, string|int $identity, #[SensitiveParameter] array $values): array
    {
        foreach (self::FIELDS[$table] ?? [] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = $this->seal($table, $identity, $column, $values[$column]);
            }
        }

        return $values;
    }

    public function reveal(string $table, string|int $identity, #[SensitiveParameter] object $row): object
    {
        $row = clone $row;
        foreach (self::FIELDS[$table] ?? [] as $column) {
            if (property_exists($row, $column)) {
                $row->{$column} = $this->open($table, $identity, $column, $row->{$column});
            }
        }

        return $row;
    }

    public function keyId(string $value): ?string
    {
        $envelope = $this->envelope($value);

        return $envelope === null ? null : explode(':', $envelope, 2)[0];
    }

    public function indexToken(string $context, string $value, ?string $keyId = null): string
    {
        $keyId ??= $this->keys()->active;
        $key = $this->indexKeys[$keyId] ??= hash_hkdf('sha256', $this->keys()->get($keyId), 32, 'fms-private-index-v1');

        return hash_hmac('sha256', json_encode([$context, $value], JSON_THROW_ON_ERROR), $key);
    }

    public function __debugInfo(): array
    {
        return ['keys' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new EncryptionFailure('Database encryption state cannot be serialized.');
    }

    private function context(string $table, string|int $identity, string $column): string
    {
        if (! in_array($column, self::FIELDS[$table] ?? [], true) || (string) $identity === '' || strlen((string) $identity) > 512) {
            throw new EncryptionFailure('Unknown protected database field or identity.');
        }

        return json_encode(['fms-database-v1', $table, (string) $identity, $column], JSON_THROW_ON_ERROR);
    }

    private function envelope(string $value): ?string
    {
        if (strlen($value) > 8388608) {
            throw new EncryptionFailure('Database ciphertext exceeds its size limit.');
        }
        if (! str_contains(substr($value, 0, 80), '__fms_encrypted_v1')) {
            return null;
        }
        $decoded = json_decode($value, true, 4);
        if (! is_array($decoded) || count($decoded) !== 1 || ! is_string($decoded['__fms_encrypted_v1'] ?? null)
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,32}:[a-zA-Z0-9+\/=]+\z/', $decoded['__fms_encrypted_v1'])) {
            throw new EncryptionFailure('Invalid encrypted database envelope.');
        }

        return $decoded['__fms_encrypted_v1'];
    }

    private function encrypter(string $id): Encrypter
    {
        return $this->encrypters[$id] ??= new Encrypter(hash_hkdf('sha256', $this->keys()->get($id), 32, 'fms-database-aes256gcm-v1'), 'aes-256-gcm');
    }
}
