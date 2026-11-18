<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Transfers;

use InvalidArgumentException;
use RuntimeException;

/** Dedicated local staging. Never accepts a client-provided filesystem path. */
final readonly class LocalPartStore
{
    public function __construct(private string $root) {}

    public function directory(string $session): string
    {
        if (! preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $session)) {
            throw new InvalidArgumentException('Invalid upload session identity.');
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$session;
    }

    /** @param resource $input @return array{size: int, sha256: string} */
    public function write(string $session, int $index, mixed $input, int $expected): array
    {
        if (! is_resource($input) || $index < 0 || $expected < 0) {
            throw new InvalidArgumentException('Invalid upload part.');
        }
        $dir = $this->directory($session);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create upload staging.');
        }
        $temporary = tempnam($dir, 'part-');
        $out = $temporary === false ? false : fopen($temporary, 'wb');
        if (! is_resource($out)) {
            throw new RuntimeException('Could not open upload staging.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            while (! feof($input)) {
                $bytes = fread($input, min(65536, max(1, $expected - $size + 1)));
                if ($bytes === false) {
                    throw new RuntimeException('Could not read upload bytes.');
                }
                $size += strlen($bytes);
                if ($size > $expected) {
                    throw new InvalidArgumentException('Upload part is larger than expected.');
                }
                hash_update($hash, $bytes);
                if ($bytes !== '' && fwrite($out, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Could not persist upload bytes.');
                }
            }
            if ($size !== $expected) {
                throw new InvalidArgumentException('Upload part is incomplete.');
            }
            fclose($out);
            $out = null;
            if (! rename($temporary, $dir.DIRECTORY_SEPARATOR.$index.'.part')) {
                throw new RuntimeException('Could not accept upload part.');
            }

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param iterable<object> $parts */
    public function assemble(string $session, iterable $parts): string
    {
        $path = tempnam($this->directory($session), 'assembled-');
        $out = $path === false ? false : fopen($path, 'wb');
        if (! is_resource($out)) {
            throw new RuntimeException('Could not assemble upload.');
        }
        try {
            foreach ($parts as $part) {
                $input = fopen($this->directory($session).DIRECTORY_SEPARATOR.$part->part_index.'.part', 'rb');
                if (! is_resource($input)) {
                    throw new RuntimeException('An accepted upload part is missing.');
                }
                try {
                    $hash = hash_init('sha256');
                    $size = 0;
                    while (! feof($input)) {
                        $bytes = fread($input, 65536);
                        if ($bytes === false) {
                            throw new RuntimeException('Could not read staged part.');
                        }
                        hash_update($hash, $bytes);
                        $size += strlen($bytes);
                        if ($bytes !== '' && fwrite($out, $bytes) !== strlen($bytes)) {
                            throw new RuntimeException('Could not assemble staged part.');
                        }
                    }
                    if ($size !== $part->size_bytes || ! hash_equals($part->sha256, hash_final($hash))) {
                        throw new RuntimeException('Staged part failed its integrity check.');
                    }
                } finally {
                    fclose($input);
                }
            }
            fclose($out);
            $out = null;

            return $path;
        } catch (\Throwable $error) {
            unlink($path);
            throw $error;
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    public function clear(string $session): void
    {
        $dir = $this->directory($session);
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if ($file->isFile() && ! unlink($file->getPathname())) {
                throw new RuntimeException('Could not remove expired upload data.');
            }
        }
        if (! rmdir($dir)) {
            throw new RuntimeException('Could not remove upload staging directory.');
        }
    }
}
