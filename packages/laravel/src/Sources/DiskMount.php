<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Support\Facades\Storage;
use LogicException;
use UseFlex\MediaStudio\Identity\Principal;

final readonly class DiskMount
{
    private function __construct(public string $key, public array $config) {}

    public static function find(string $key): ?self
    {
        $config = config('media-studio.sources.mounts.'.$key);
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $key) || ! is_array($config) || ($config['enabled'] ?? true) !== true) {
            return null;
        }
        if (! is_string($config['disk'] ?? null) || ! is_callable($config['authorize'] ?? null)) {
            throw new LogicException('Disk sources require a disk and an explicit authorize callback.');
        }

        return new self($key, $config);
    }

    public function allows(object $workspace, Principal $principal): bool
    {
        return ($this->config['authorize'])($workspace, $principal) === true;
    }

    public function path(string $path): string
    {
        abort_unless($path !== '' && strlen($path) <= 1024 && ! str_contains($path, '\\') && ! preg_match('/[\x00-\x1f\x7f]/', $path), 422, 'Invalid source path.');
        $segments = explode('/', $path);
        abort_if(array_intersect($segments, ['', '.', '..']) !== [], 422, 'Invalid source path.');
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        abort_unless($prefix === '' || str_starts_with($path, $prefix.'/'), 422, 'The object is outside the configured source prefix.');

        return $path;
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([$this->key, $this->config['disk'], $this->config['prefix'] ?? '', $this->config['revision'] ?? 1, array_intersect_key(Storage::disk($this->config['disk'])->getConfig(), array_flip(['driver', 'root', 'bucket', 'endpoint', 'region']))], JSON_THROW_ON_ERROR));
    }
}
