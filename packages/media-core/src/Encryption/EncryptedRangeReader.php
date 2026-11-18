<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Encryption;

use Closure;
use InvalidArgumentException;
use SensitiveParameter;

/** Authenticates an entire segment before releasing any plaintext from it. */
final class EncryptedRangeReader
{
    private Closure $readAt;

    private string $derived;

    private string $noncePrefix;

    private int $segments;

    public readonly int $size;

    private int $cachedIndex = -1;

    private string $cachedPlain = '';

    public function __construct(callable $readAt, private readonly int $ciphertextBytes, #[SensitiveParameter] string $key, string $associatedData, private readonly int $segmentBytes)
    {
        if (strlen($key) !== 32 || ! in_array($segmentBytes, [4096, 65536, 1048576], true) || $ciphertextBytes < 56) {
            throw new EncryptionFailure('Invalid encrypted stream parameters.');
        }
        $this->readAt = Closure::fromCallable($readAt);
        $header = $this->exact(0, AesGcmHkdfStream::HEADER_BYTES);
        if (ord($header[0]) !== AesGcmHkdfStream::HEADER_BYTES) {
            throw new EncryptionFailure('Invalid encrypted stream header.');
        }
        $this->segments = intdiv($ciphertextBytes - 1, $segmentBytes) + 1;
        $lastBytes = $ciphertextBytes - ($this->segments - 1) * $segmentBytes;
        if ($this->segments > 0x100000000 || ($this->segments > 1 && $lastBytes < 17)) {
            throw new EncryptionFailure('Invalid encrypted stream length.');
        }
        $this->size = $ciphertextBytes - AesGcmHkdfStream::HEADER_BYTES - $this->segments * AesGcmHkdfStream::TAG_BYTES;
        $this->noncePrefix = substr($header, 33, 7);
        $this->derived = hash_hkdf('sha256', $key, 32, $associatedData, substr($header, 1, 32));
    }

    /** Returns at most one segment's worth of bytes, even across a segment boundary. */
    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $length > $this->segmentBytes || $offset > $this->size) {
            throw new InvalidArgumentException('Invalid plaintext range.');
        }
        $remaining = min($length, $this->size - $offset);
        if ($this->size === 0) {
            $this->segment(0); // Empty files still have an authentication tag.
        }
        $result = '';
        while ($remaining > 0) {
            $firstCapacity = $this->segmentBytes - AesGcmHkdfStream::HEADER_BYTES - AesGcmHkdfStream::TAG_BYTES;
            $capacity = $this->segmentBytes - AesGcmHkdfStream::TAG_BYTES;
            $index = $offset < $firstCapacity ? 0 : 1 + intdiv($offset - $firstCapacity, $capacity);
            $start = $index === 0 ? 0 : $firstCapacity + ($index - 1) * $capacity;
            $plain = $this->segment($index);
            $take = min($remaining, strlen($plain) - ($offset - $start));
            if ($take <= 0) {
                throw new EncryptionFailure('Encrypted stream ended unexpectedly.');
            }
            $result .= substr($plain, $offset - $start, $take);
            $offset += $take;
            $remaining -= $take;
        }

        return $result;
    }

    /** Verify the authenticated final marker, including boundary truncation. */
    public function verifyEnd(): void
    {
        $this->segment($this->segments - 1);
    }

    public function __debugInfo(): array
    {
        return ['size' => $this->size, 'segmentBytes' => $this->segmentBytes];
    }

    public function __serialize(): array
    {
        throw new EncryptionFailure('Decryption state cannot be serialized.');
    }

    public function __destruct()
    {
        if (isset($this->derived) && function_exists('sodium_memzero')) {
            sodium_memzero($this->derived);
        }
    }

    private function segment(int $index): string
    {
        if ($index === $this->cachedIndex) {
            return $this->cachedPlain;
        }
        $offset = $index === 0 ? AesGcmHkdfStream::HEADER_BYTES : $index * $this->segmentBytes;
        $length = min(($index === 0 ? $this->segmentBytes - AesGcmHkdfStream::HEADER_BYTES : $this->segmentBytes), $this->ciphertextBytes - $offset);
        $bytes = $this->exact($offset, $length);
        $plain = openssl_decrypt(substr($bytes, 0, -AesGcmHkdfStream::TAG_BYTES), 'aes-256-gcm', $this->derived, OPENSSL_RAW_DATA,
            $this->noncePrefix.pack('N', $index).chr((int) ($index === $this->segments - 1)), substr($bytes, -AesGcmHkdfStream::TAG_BYTES), '');
        if ($plain === false) {
            throw new EncryptionFailure('Encrypted segment authentication failed.');
        }
        $this->cachedIndex = $index;
        $this->cachedPlain = $plain;

        return $plain;
    }

    private function exact(int $offset, int $length): string
    {
        $bytes = ($this->readAt)($offset, $length);
        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new EncryptionFailure('Ciphertext read was incomplete.');
        }

        return $bytes;
    }
}
