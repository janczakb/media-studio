<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class AnalysisAdmission
{
    public function schedule(object $workspace, object $entry, Principal $actor): void
    {
        if (config('media-studio.intelligence.enabled') !== true || config('media-studio.intelligence.automatic') !== true || ! in_array($entry->media_type, ['image', 'video'], true) || config('media-studio.intelligence.'.($entry->media_type === 'video' ? 'videos' : 'images')) !== true) {
            return;
        }
        $connection = config('media-studio.intelligence.connection') ?? config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            return; // Never charge/parse in the upload request through a sync queue.
        }
        $maximum = (int) config('media-studio.intelligence.max_pending_per_workspace', 100);
        if ($maximum < 1 || $maximum > 10000) {
            throw new \LogicException('Invalid AI queue admission limit.');
        }
        $pending = DB::table('fms_analysis_jobs as j')->join('fms_entries as e', 'e.id', '=', 'j.entry_id')->where('e.workspace_id', $workspace->id)->whereIn('j.state', ['queued', 'running'])->limit($maximum)->pluck('j.version_id');
        if (count($pending) >= $maximum || ($entry->size_bytes ?? PHP_INT_MAX) > (int) config($entry->media_type === 'video' ? 'media-studio.intelligence.video.max_source_bytes' : 'media-studio.intelligence.max_source_bytes', 10485760)) {
            return;
        }
        $tenant = TenantIdentity::fromWorkspace($workspace);
        $inserted = DB::table('fms_analysis_jobs')->insertOrIgnore(['version_id' => $entry->current_version_id, 'entry_id' => $entry->id, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        if ($inserted) {
            $job = new AnalyzeImageJob($workspace->public_id, $entry->public_id, $entry->current_version_id, $actor->type, $actor->id, $tenant?->type, $tenant?->id);
            $job->onConnection($connection)->onQueue(config('media-studio.intelligence.queue', 'media-intelligence'));
            DB::afterCommit(function () use ($job, $entry): void {
                try {
                    app(Dispatcher::class)->dispatch($job);
                } catch (\Throwable) {
                    DB::table('fms_analysis_jobs')->where('version_id', $entry->current_version_id)->update(['state' => 'dispatch_failed', 'updated_at' => now()]);
                }
            });
        }
    }
}
