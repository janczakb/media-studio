<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use UseFlex\MediaCore\Encryption\EncryptedRangeReader;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Private process-local handle handoff; no file/key material in the URL. */
final class EncryptedReadStream
{
    public mixed $context;

    private static array $pending = [];

    private static bool $registered = false;

    private ?EncryptedRangeReader $reader = null;

    private int $position = 0;

    public static function open(EncryptedRangeReader $reader): mixed
    {
        if (! self::$registered) {
            if (in_array('fmsencrypted', stream_get_wrappers(), true) || ! stream_wrapper_register('fmsencrypted', self::class)) {
                throw new EncryptionFailure('Encrypted stream wrapper could not be registered.');
            }
            self::$registered = true;
        }
        $id = bin2hex(random_bytes(16));
        self::$pending[$id] = $reader;
        try {
            $resource = fopen('fmsencrypted://'.$id, 'rb');
            if ($resource === false) {
                throw new EncryptionFailure('Encrypted stream could not be opened.');
            }

            return $resource;
        } finally {
            unset(self::$pending[$id]);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $id = substr($path, strlen('fmsencrypted://'));
        if ($mode !== 'rb' || ! isset(self::$pending[$id])) {
            return false;
        }
        $this->reader = self::$pending[$id];
        unset(self::$pending[$id]);

        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = '';
        $remaining = min($count, 65536, $this->reader->size - $this->position);
        while ($remaining > 0) {
            $chunk = $this->reader->read($this->position, min($remaining, 4096));
            if ($chunk === '') {
                throw new EncryptionFailure('Encrypted stream ended unexpectedly.');
            }
            $this->position += strlen($chunk);
            $remaining -= strlen($chunk);
            $bytes .= $chunk;
        }

        return $bytes;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $next = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->reader->size + $offset,
            default => -1,
        };
        if ($next < 0 || $next > $this->reader->size) {
            return false;
        }
        $this->position = $next;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= $this->reader->size;
    }

    public function stream_stat(): array
    {
        return ['size' => $this->reader->size, 7 => $this->reader->size, 'mode' => 0100400, 2 => 0100400];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->reader = null;
    }
}
