<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Durable keyset migration/rekey; never holds a whole table or long transaction. */
final class DatabaseEncryptionMigration
{
    private const IDENTITIES = [
        'fms_ai_conversations' => ['id'], 'fms_ai_messages' => ['id'], 'fms_ai_runs' => ['id'],
        'fms_ai_events' => ['id'], 'fms_ai_actions' => ['id'], 'fms_ai_tool_receipts' => ['run_id', 'call_id'],
        'fms_comments' => ['public_id'], 'fms_notes' => ['entry_id'], 'fms_entries' => ['id'],
    ];

    public function __construct(private DatabasePayloads $cipher) {}

    public function start(): string
    {
        $this->requireEnabled();
        $id = (string) Str::ulid();
        DB::table('fms_encryption_operations')->insert(['id' => $id, 'kind' => 'database', 'state' => 'pending',
            'options' => json_encode(['key' => $this->fingerprint()], JSON_THROW_ON_ERROR),
            'checkpoint' => json_encode(['table' => 0, 'after' => null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function step(string $id, int $limit = 25, int $seconds = 5): object
    {
        $this->requireEnabled();
        $limit = min(100, max(1, $limit));
        $deadline = microtime(true) + min(15, max(1, $seconds));
        $lease = bin2hex(random_bytes(16));
        $claimed = DB::table('fms_encryption_operations')->where('id', $id)->where('kind', 'database')->where('state', '!=', 'completed')
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->update(['lease' => $lease, 'lease_until' => now()->addMinutes(2), 'state' => 'running', 'error' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return DB::table('fms_encryption_operations')->where('id', $id)->where('kind', 'database')->firstOrFail();
        }
        try {
            for ($processed = 0; $processed < $limit && microtime(true) < $deadline; $processed++) {
                $more = DB::transaction(function () use ($id, $lease): bool {
                    $operation = DB::table('fms_encryption_operations')->where('id', $id)->lockForUpdate()->firstOrFail();
                    if ($operation->lease !== $lease || strtotime($operation->lease_until) <= time()) {
                        throw new EncryptionFailure('Migration lease was lost.');
                    }
                    if (! hash_equals(json_decode($operation->options, true, flags: JSON_THROW_ON_ERROR)['key'], $this->fingerprint())) {
                        throw new EncryptionFailure('Migration key configuration changed.');
                    }
                    $checkpoint = json_decode($operation->checkpoint, true, flags: JSON_THROW_ON_ERROR);
                    $tables = array_keys(self::IDENTITIES);
                    while (($table = $tables[$checkpoint['table']] ?? null) !== null) {
                        if (! Schema::hasTable($table)) {
                            $checkpoint = ['table' => $checkpoint['table'] + 1, 'after' => null];

                            continue;
                        }
                        $columns = self::IDENTITIES[$table];
                        $query = DB::table($table);
                        if ($table === 'fms_entries') {
                            $query->whereNotNull('note_excerpt');
                        }
                        if ($checkpoint['after'] !== null) {
                            $after = $checkpoint['after'];
                            $query->where(function ($q) use ($columns, $after) {
                                $q->where($columns[0], '>', $after[0]);
                                if (count($columns) === 2) {
                                    $q->orWhere(fn ($q) => $q->where($columns[0], $after[0])->where($columns[1], '>', $after[1]));
                                }
                            });
                        }
                        foreach ($columns as $column) {
                            $query->orderBy($column);
                        }
                        $row = $query->lockForUpdate()->first();
                        if ($row === null) {
                            $checkpoint = ['table' => $checkpoint['table'] + 1, 'after' => null];

                            continue;
                        }
                        $identity = implode('|', array_map(fn ($column) => (string) $row->{$column}, $columns));
                        $changes = [];
                        if ($table === 'fms_ai_conversations') {
                            $title = $row->encrypted_title === null ? $row->title : $this->cipher->open($table, $identity, 'encrypted_title', $row->encrypted_title);
                            $changes = app(ConversationTitles::class)->fields($identity, (int) $row->workspace_id, $row->principal_key, $title);
                        } else {
                            foreach (DatabasePayloads::FIELDS[$table] as $column) {
                                $stored = $row->{$column};
                                if ($stored === null) {
                                    continue;
                                }
                                $keyId = $this->cipher->keyId($stored);
                                $plain = $keyId === null ? $stored : $this->cipher->open($table, $identity, $column, $stored);
                                if ($keyId === $this->cipher->keys()->active) {
                                    continue;
                                }
                                $sealed = $this->cipher->seal($table, $identity, $column, $plain);
                                if (! hash_equals(hash('sha256', $plain), hash('sha256', $this->cipher->open($table, $identity, $column, $sealed)))) {
                                    throw new EncryptionFailure('Migration verification failed.');
                                }
                                $changes[$column] = $sealed;
                            }
                        }
                        if ($changes !== []) {
                            $write = DB::table($table);
                            foreach ($columns as $column) {
                                $write->where($column, $row->{$column});
                            }
                            $write->update($changes);
                        }
                        $checkpoint['after'] = array_map(fn ($column) => $row->{$column}, $columns);
                        DB::table('fms_encryption_operations')->where('id', $id)->update(['checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR), 'processed' => $operation->processed + 1, 'lease_until' => now()->addMinutes(2), 'updated_at' => now()]);

                        return true;
                    }
                    DB::table('fms_encryption_operations')->where('id', $id)->update(['state' => 'completed', 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

                    return false;
                }, 3);
                if (! $more) {
                    break;
                }
            }
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->where('state', 'running')->update(['state' => 'pending', 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->update(['state' => 'failed', 'error' => 'Migration stopped without advancing the failed record. Check keys, schema and ciphertext integrity.', 'updated_at' => now()]);
            throw new EncryptionFailure('Database encryption migration failed; the checkpoint was retained.');
        } finally {
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null]);
        }

        return DB::table('fms_encryption_operations')->where('id', $id)->firstOrFail();
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', 'fms-db-migration-v1:'.$this->cipher->keys()->active, $this->cipher->keys()->get($this->cipher->keys()->active));
    }

    private function requireEnabled(): void
    {
        if (! $this->cipher->enabled()) {
            throw new EncryptionFailure('Enable database encryption before migrating so concurrent application writes are protected.');
        }
    }
}
