<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Private local checkpoint; only the completed JSONL is exposed to importers. */
final class S3Inventory
{
    public function scan(AwsS3V3Adapter $disk, DiskMount $mount, string $output, string $scope, bool $resume, int $pages, callable $authorize): array
    {
        abort_unless($pages >= 1 && $pages <= 1000, 422, 'Pages must be between 1 and 1000.');
        abort_if(is_file($output) || is_link($output), 409, 'The output already exists.');
        $partial = $output.'.partial';
        $checkpoint = $output.'.checkpoint';
        $lockPath = $output.'.lock';
        abort_if(is_link($lockPath) || is_link($partial) || is_link($checkpoint), 422);
        $mask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($mask);
        }
        abort_unless(is_resource($lock), 422, 'Cannot open inventory lock.');
        $stream = null;
        try {
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 409, 'Another inventory process is active.');
            abort_if(file_exists($output), 409, 'The output already exists.');
            $config = $disk->getConfig();
            $identity = hash('sha256', json_encode([$scope, $mount->revision(), $config['bucket'], $config['root'] ?? '', $config['endpoint'] ?? null], JSON_THROW_ON_ERROR));
            if ($resume) {
                abort_unless(is_file($checkpoint) && is_file($partial), 422, 'No inventory checkpoint is available.');
                $state = json_decode(Crypt::decryptString(file_get_contents($checkpoint)), true, 16, JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($identity, $state['identity']), 409, 'Inventory scope changed.');
                $stream = fopen($partial, 'r+b');
                abort_unless(fstat($stream)['size'] >= $state['offset'], 409, 'Inventory data is incomplete.');
                // Discard an uncommitted tail left by an interrupted write.
                if (! ftruncate($stream, $state['offset']) || fseek($stream, $state['offset']) !== 0) {
                    throw new RuntimeException('Inventory recovery failed.');
                }
            } else {
                abort_if(file_exists($checkpoint) || file_exists($partial), 409, 'Resume the existing inventory or choose another output.');
                $mask = umask(0077);
                try {
                    $stream = fopen($partial, 'x+b');
                } finally {
                    umask($mask);
                }
                $state = ['identity' => $identity, 'offset' => 0, 'count' => 0, 'token' => null, 'complete' => false];
                $this->save($checkpoint, $state);
            }
            $prefix = trim((string) ($mount->config['prefix'] ?? ''), '/');
            $prefix = rtrim($disk->path($prefix), '/');
            $prefix = $prefix === '' ? '' : $prefix.'/';
            $root = $disk->path('');
            for ($page = 0; $page < $pages && ! $state['complete']; $page++) {
                $authorize();
                $request = ['Bucket' => $config['bucket'], 'Prefix' => $prefix, 'MaxKeys' => 1000];
                if ($state['token'] !== null) {
                    $request['ContinuationToken'] = $state['token'];
                }
                $result = $disk->getClient()->listObjectsV2($request);
                $next = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
                abort_if(($result['IsTruncated'] ?? false) && (! is_string($next) || $next === '' || $next === $state['token']), 502, 'Storage returned an invalid continuation.');
                $contents = $result['Contents'] ?? [];
                abort_unless(count($contents) <= 1000, 502);
                foreach ($contents as $object) {
                    $key = (string) ($object['Key'] ?? '');
                    abort_unless(str_starts_with($key, $prefix) && str_starts_with($key, $root), 502, 'Storage returned an out-of-scope object.');
                    if (str_ends_with($key, '/')) {
                        continue;
                    }
                    $path = $mount->path(substr($key, strlen($root)));
                    $line = json_encode(['path' => $path], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        throw new RuntimeException('Inventory write failed.');
                    }
                    $state['count']++;
                }
                if (! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('Inventory synchronization failed.');
                }
                $authorize();
                $state['offset'] = ftell($stream);
                $state['token'] = $next;
                $state['complete'] = $next === null;
                $this->save($checkpoint, $state);
            }
            if ($state['complete']) {
                $authorize();
                if (! rename($partial, $output)) {
                    throw new RuntimeException('Inventory publication failed.');
                }
                unlink($checkpoint);
            }

            return ['complete' => $state['complete'], 'files' => $state['count']];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function save(string $path, array $state): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8));
        $mask = umask(0077);
        try {
            $stream = fopen($temporary, 'xb');
        } finally {
            umask($mask);
        }
        try {
            $bytes = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Checkpoint write failed.');
            }
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Checkpoint publication failed.');
            }
        } finally {
            fclose($stream);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
