<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Activity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;

final class InboxSummary
{
    private function key(int $workspace, Principal $principal): string
    {
        return config('media-studio.cache.prefix', 'fms').':inbox:'.hash('sha256', json_encode([DB::connection()->getName(), DB::connection()->getDatabaseName(), $workspace, $principal->key()], JSON_THROW_ON_ERROR));
    }

    public function count(int $workspace, Principal $principal): int
    {
        $read = static fn () => DB::table('fms_notifications')->where('workspace_id', $workspace)->where('principal_key', $principal->key())->whereNull('read_at')->limit(10)->get(['id'])->count();
        try {
            return Cache::store(config('media-studio.cache.store'))->remember($this->key($workspace, $principal), 15, $read);
        } catch (\Throwable) {
            // The cache is an optimization; a capped indexed read remains safe.
            return $read();
        }
    }

    public function forget(int $workspace, Principal $principal): void
    {
        try {
            Cache::store(config('media-studio.cache.store'))->forget($this->key($workspace, $principal));
        } catch (\Throwable) {
            // A cache outage must not turn a committed file mutation into a failure.
        }
    }
}
