<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

final class InventoryPage
{
    public static function read(DiskMount $mount, int $offset = 0, int $limit = 60, ?string $identity = null): array
    {
        $path = $mount->config['inventory'] ?? null;
        abort_unless(is_string($path) && is_file($path) && is_readable($path), 422, 'No prepared inventory is available for this source.');
        $stream = @fopen($path, 'rb');
        abort_unless(is_resource($stream), 422, 'The prepared inventory could not be opened.');
        try {
            abort_unless(flock($stream, LOCK_SH), 409, 'Inventory is busy.');
            $stat = fstat($stream);
            $current = hash('sha256', json_encode([realpath($path), $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']], JSON_THROW_ON_ERROR));
            abort_unless($identity === null || hash_equals($identity, $current), 409, 'Inventory changed. Start a new import.');
            abort_unless($offset >= 0 && $offset <= $stat['size'] && $limit >= 0 && $limit <= 120, 422);
            fseek($stream, $offset);
            $paths = [];
            while (count($paths) < $limit && ($line = fgets($stream, 8194)) !== false) {
                abort_unless(strlen($line) <= 8192, 422, 'Inventory record is too long.');
                try {
                    $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    abort(422, 'Inventory contains an invalid JSON record.');
                }
                abort_unless(is_array($record) && is_string($record['path'] ?? null), 422, 'Each inventory record requires path.');
                $paths[] = $mount->path($record['path']);
            }

            return ['identity' => $current, 'bytes' => (int) $stat['size'], 'offset' => ftell($stream), 'paths' => $paths];
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
