<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Cache only immutable, non-sensitive catalogs. Authorize each request separately. */
final class StudioCache
{
    public function catalog(string $name, string $fingerprint, Closure $resolve): mixed
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name) || strlen($fingerprint) > 4096) {
            throw new InvalidArgumentException('Invalid Studio catalog cache identity.');
        }
        $ttl = min(86400, max(0, (int) config('media-studio.cache.catalog_ttl', 300)));
        if ($ttl === 0) {
            return $resolve();
        }
        $prefix = (string) config('media-studio.cache.prefix', 'fms');
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/', $prefix)) {
            throw new InvalidArgumentException('Invalid Studio cache prefix.');
        }
        $key = $prefix.':catalog:v1:'.$name.':'.hash('sha256', $fingerprint);

        return Cache::store(config('media-studio.cache.store'))->remember($key, $ttl, $resolve);
    }
}
