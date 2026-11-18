<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class ContentAdmission
{
    public function schedule(object $workspace, object $entry, Principal $actor): void
    {
        if (config('media-studio.search.content.enabled') !== true || config('media-studio.search.content.automatic') !== true || $entry->current_version_id === null) {
            return;
        }
        $connection = config('media-studio.search.content.connection') ?? config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            return;
        }
        $maximum = (int) config('media-studio.search.content.max_pending_per_workspace', 100);
        if ($maximum < 1 || $maximum > 10000) {
            throw new \LogicException('Invalid content queue admission limit.');
        }
        $pending = DB::table('fms_content_jobs as j')->join('fms_entries as e', 'e.id', '=', 'j.entry_id')->where('e.workspace_id', $workspace->id)->whereIn('j.state', ['queued', 'running'])->limit($maximum)->pluck('j.version_id');
        if ($pending->count() >= $maximum || ($entry->size_bytes ?? PHP_INT_MAX) > (int) config('media-studio.search.content.max_source_bytes', 1048576)) {
            return;
        }
        $tenant = TenantIdentity::fromWorkspace($workspace);
        if (! DB::table('fms_content_jobs')->insertOrIgnore(['version_id' => $entry->current_version_id, 'entry_id' => $entry->id, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()])) {
            return;
        }
        $job = new ExtractContentJob($workspace->public_id, $entry->public_id, $entry->current_version_id, $actor->type, $actor->id, $tenant?->type, $tenant?->id);
        $job->onConnection($connection)->onQueue(config('media-studio.search.content.queue', 'media-content'));
        DB::afterCommit(function () use ($job, $entry): void {
            try {
                app(Dispatcher::class)->dispatch($job);
            } catch (\Throwable) {
                DB::table('fms_content_jobs')->where('version_id', $entry->current_version_id)->update(['state' => 'dispatch_failed', 'updated_at' => now()]);
            }
        });
    }
}
