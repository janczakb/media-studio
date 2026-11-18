<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Encryption;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * RAW AES256-GCM-HKDF-SHA256 Streaming AEAD, following the published Tink format.
 * https://developers.google.com/tink/streaming-aead/aes_gcm_hkdf_streaming
 * This is an independent PHP implementation, not Google's Tink library or certification.
 * Immutable ciphertext only: editing a segment requires a fresh encryption of the file.
 */
final class AesGcmHkdfStream
{
    public const HEADER_BYTES = 40;

    public const TAG_BYTES = 16;

    public function __construct(#[SensitiveParameter] private readonly string $key, public readonly int $segmentBytes = 65536)
    {
        if (strlen($key) !== 32 || ! in_array($segmentBytes, [4096, 65536, 1048576], true)) {
            throw new InvalidArgumentException('A 256-bit key and supported segment size are required.');
        }
        if (! extension_loaded('openssl') || ! in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new EncryptionFailure('AES-256-GCM is unavailable.');
        }
    }

    /** Caller owns both blocking streams. Output must be unpublished until this succeeds. */
    public function encrypt(mixed $input, mixed $output, string $associatedData): void
    {
        self::assertStream($input);
        self::assertStream($output);
        $salt = random_bytes(32);
        $noncePrefix = random_bytes(7);
        $header = chr(self::HEADER_BYTES).$salt.$noncePrefix;
        $derived = hash_hkdf('sha256', $this->key, 32, $associatedData, $salt);
        try {
            self::writeAll($output, $header);
            $index = 0;
            $carry = '';
            do {
                if ($index > 0xFFFFFFFF) {
                    throw new EncryptionFailure('Encrypted stream exceeds its segment limit.');
                }
                $capacity = $this->segmentBytes - self::TAG_BYTES - ($index === 0 ? self::HEADER_BYTES : 0);
                $plain = $carry.self::readUpTo($input, $capacity - strlen($carry));
                $carry = self::readUpTo($input, 1);
                $last = $carry === '';
                $tag = '';
                $cipher = openssl_encrypt($plain, 'aes-256-gcm', $derived, OPENSSL_RAW_DATA, $noncePrefix.pack('N', $index).chr((int) $last), $tag, '', self::TAG_BYTES);
                if ($cipher === false || strlen($tag) !== self::TAG_BYTES) {
                    throw new EncryptionFailure('Stream encryption failed.');
                }
                self::writeAll($output, $cipher.$tag);
                $index++;
            } while (! $last);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($derived);
            }
        }
    }

    /** readAt must return precisely the requested ciphertext bytes, with bounded I/O. */
    public function reader(callable $readAt, int $ciphertextBytes, string $associatedData): EncryptedRangeReader
    {
        return new EncryptedRangeReader($readAt, $ciphertextBytes, $this->key, $associatedData, $this->segmentBytes);
    }

    public function __debugInfo(): array
    {
        return ['segmentBytes' => $this->segmentBytes, 'key' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new EncryptionFailure('Encryption keys cannot be serialized.');
    }

    private static function assertStream(mixed $stream): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream' || (stream_get_meta_data($stream)['blocked'] ?? true) !== true) {
            throw new InvalidArgumentException('A blocking stream is required.');
        }
    }

    private static function readUpTo(mixed $input, int $bytes): string
    {
        $result = '';
        while (strlen($result) < $bytes) {
            $chunk = fread($input, $bytes - strlen($result));
            if ($chunk === false || ($chunk === '' && ! feof($input))) {
                throw new EncryptionFailure('Input stream was interrupted.');
            }
            if ($chunk === '') {
                break;
            }
            $result .= $chunk;
        }

        return $result;
    }

    private static function writeAll(mixed $output, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new EncryptionFailure('Encrypted output could not be written.');
            }
            $offset += $written;
        }
    }
}
