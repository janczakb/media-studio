<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use UseFlex\MediaStudio\Identity\Principal;

final class ImportDiskManifest extends Command
{
    protected $signature = 'media-studio:sources:import {workspace} {mount} {principal-type} {principal-id} {manifest}
        {--cursor= : Continuation printed by the previous batch} {--limit=60 : Maximum records, 1–120}
        {--apply : Write the indexed projection}';

    protected $description = 'Inspect or import a bounded JSONL inventory without copying source objects.';

    public function handle(DiskIndexer $indexer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        $path = (string) $this->argument('manifest');
        if ($limit === false || ! is_file($path) || ! is_readable($path)) {
            $this->error('Provide a readable local JSONL inventory and a limit between 1 and 120.');

            return self::INVALID;
        }
        $mount = DiskMount::find((string) $this->argument('mount'));
        abort_if($mount === null, 404);
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        abort_unless(is_string(config('media-studio.principals.'.$principal->type)) && $principal->id !== '' && strlen($principal->id) <= 191, 422);
        $stream = fopen($path, 'rb');
        try {
            abort_unless(flock($stream, LOCK_SH), 409, 'Inventory is busy.');
            $stat = fstat($stream);
            // Trusted local immutable inventory: constant-time identity, not a full-file hash per page.
            $scope = hash('sha256', json_encode([realpath($path), $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'], $this->argument('workspace'), $mount->revision(), $principal->key(), (bool) $this->option('apply')], JSON_THROW_ON_ERROR));
            $offset = 0;
            if ($this->option('cursor') !== null) {
                try {
                    $cursor = json_decode(Crypt::decryptString((string) $this->option('cursor')), true, 8, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $this->error('Invalid inventory cursor.');

                    return self::INVALID;
                }
                if (! is_array($cursor) || ($cursor['scope'] ?? null) !== $scope || ($cursor['expires'] ?? 0) < time() || ! is_int($cursor['offset'] ?? null) || $cursor['offset'] < 0 || $cursor['offset'] > $stat['size']) {
                    $this->error('Inventory or import scope changed. Start a new inventory pass.');

                    return self::INVALID;
                }
                $offset = $cursor['offset'];
            }
            fseek($stream, $offset);
            $paths = [];
            while (count($paths) < $limit && ($line = fgets($stream, 8194)) !== false) {
                if (strlen($line) > 8192) {
                    $this->error('Inventory record exceeds 8192 bytes.');

                    return self::INVALID;
                }
                $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                abort_unless(is_array($record) && is_string($record['path'] ?? null), 422, 'Each record requires path.');
                $paths[] = $record['path'];
            }
            $next = ftell($stream);
            $result = $paths === [] ? ['inspected' => 0, 'imported' => 0] : $indexer->importPage($principal, (string) $this->argument('workspace'), $mount->key, $paths, (bool) $this->option('apply'));
            $this->info(json_encode([...$result, 'nextCursor' => $next >= $stat['size'] ? null : Crypt::encryptString(json_encode(['scope' => $scope, 'offset' => $next, 'expires' => time() + 604800], JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR));
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }

        return self::SUCCESS;
    }
}
