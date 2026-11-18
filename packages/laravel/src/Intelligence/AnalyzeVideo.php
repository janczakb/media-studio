<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Video\VideoFrames;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;

/** Sampled-frame analysis is explicitly partial; never claims unseen events. */
final class AnalyzeVideo
{
    public function run(Principal $actor, string $workspaceId, string $entryId, ?TenantIdentity $tenant): string
    {
        $profile = app(AnalysisProfile::class)->image(video: true);
        $access = app(WorkspaceAccess::class);
        $workspace = $access->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
        $entry = app(Entries::class)->resolve($workspace->id, $entryId);
        abort_unless($entry->source_kind === 'managed' && $entry->trashed_at === null && $entry->media_type === 'video', 403);
        $version = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->where('id', $entry->current_version_id)->first();
        abort_if($version === null, 404);
        $limit = (int) config('media-studio.intelligence.video.max_source_bytes', 134217728);
        $count = (int) config('media-studio.intelligence.video.max_frames', 4);
        $maxDuration = (int) config('media-studio.intelligence.video.max_duration_seconds', 600);
        $maxSeconds = (int) config('media-studio.intelligence.video.max_seconds', 480);
        $deadline = hrtime(true) + $maxSeconds * 1000000000;
        if ($maxSeconds < 60 || $maxSeconds > 480 || $limit < 1 || $limit > 268435456 || $count < 1 || $count > 12 || $maxDuration < 1 || $maxDuration > 3600) {
            throw new \LogicException('Invalid video analysis budget.');
        }
        if ($version->size_bytes > $limit) {
            return 'skipped_size';
        }
        $demuxer = match ($version->mime_type) {
            'video/mp4', 'video/quicktime', 'video/x-m4v' => 'mov',
            'video/webm', 'video/x-matroska' => 'matroska',
            default => null,
        };
        if ($demuxer === null) {
            return 'unsupported';
        }
        $ffmpeg = $this->binary('ffmpeg');
        $ffprobe = $this->binary('ffprobe');
        $frames = new VideoFrames(app(ProcessExecutor::class), $ffmpeg, $ffprobe);
        $id = hash('sha256', json_encode([$workspace->id, $version->id, $profile['provider'], $profile['model'] ?? null, $profile['max_labels'], $count, $maxDuration, 'video-sampled-v1'], JSON_THROW_ON_ERROR));
        $lock = Cache::store(config('media-studio.intelligence.cache_store'))->lock('fms:analysis:'.$id, 600);
        if (! $lock->get()) {
            return 'busy';
        }
        $source = null;
        try {
            if (DB::table('fms_image_analyses')->where('id', $id)->exists()) {
                return 'ready_partial';
            }
            $source = tmpfile();
            $input = Storage::disk($version->disk)->readStream($version->object_key);
            if (! is_resource($source) || ! is_resource($input)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new \RuntimeException('Video analysis source unavailable.');
            }
            try {
                $copied = stream_copy_to_stream($input, $source, $limit + 1);
            } finally {
                fclose($input);
            }
            $path = stream_get_meta_data($source)['uri'];
            chmod($path, 0600);
            if ($copied === false || $copied > $limit || ! is_string($version->sha256) || ! hash_equals($version->sha256, hash_file('sha256', $path))) {
                throw new \RuntimeException('Video source changed or exceeds limits.');
            }
            $probe = $frames->inspect($path, $demuxer);
            $duration = (float) ($probe['format']['duration'] ?? 0);
            $width = (int) ($probe['streams'][0]['width'] ?? 0);
            $height = (int) ($probe['streams'][0]['height'] ?? 0);
            if (! is_finite($duration) || $duration <= 0 || $duration > $maxDuration || $width < 1 || $height < 1 || $width * $height > 40000000) {
                return 'skipped_duration_or_dimensions';
            }
            $labels = [];
            $segments = [];
            $seen = [];
            for ($index = 0; $index < $count; $index++) {
                // Reserve time for one bounded decoder/provider call and publication.
                if (hrtime(true) + 45000000000 > $deadline) {
                    throw new \RuntimeException('Video analysis elapsed-time budget exceeded.');
                }
                // Recheck before every potentially billable frame, not only on publication.
                $access->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
                abort_unless(DB::table('fms_entries')->where('id', $entry->id)->where('current_version_id', $version->id)->whereNull('trashed_at')->exists(), 409);
                $time = ($index + 0.5) * $duration / $count;
                $bytes = $frames->frame($path, $demuxer, $time);
                $frameKey = hash('sha256', $bytes);
                $result = $seen[$frameKey] ??= app($profile['adapter'])->analyze($bytes, 'image/jpeg', $profile);
                foreach ($result->labels as $label) {
                    $key = mb_strtolower($label['name']);
                    if (! isset($labels[$key]) || ($label['score'] ?? 0) > ($labels[$key]['score'] ?? 0)) {
                        $labels[$key] = $label;
                    }
                }
                $segments[] = ['time' => round($time, 3), 'description' => $result->description, 'labels' => array_column($result->labels, 'name')];
            }
            $labels = array_slice(array_values($labels), 0, 64);
            DB::transaction(function () use ($access, $actor, $workspaceId, $tenant, $entry, $version, $id, $profile, $labels, $segments): void {
                $scope = $access->resolveForWorker($actor, $workspaceId, $tenant, lock: true, operation: 'files.process');
                $current = DB::table('fms_entries')->where('workspace_id', $scope->id)->where('id', $entry->id)->lockForUpdate()->first();
                abort_unless($current !== null && $current->trashed_at === null && $current->current_version_id === $version->id, 409);
                DB::table('fms_image_analyses')->insert(['id' => $id, 'entry_id' => $entry->id, 'version_id' => $version->id, 'provider' => $profile['provider'], 'description' => mb_substr(implode(' ', array_column($segments, 'description')), 0, 2000), 'created_at' => now()]);
                if ($labels !== []) {
                    DB::table('fms_ai_labels')->insert(array_map(static fn ($label): array => ['analysis_id' => $id, 'name' => $label['name'], 'normalized_name' => mb_strtolower($label['name']), 'score' => $label['score']], $labels));
                }
                foreach ($segments as $index => $segment) {
                    DB::table('fms_analysis_segments')->insert(['analysis_id' => $id, 'ordinal' => $index, 'time_seconds' => $segment['time'], 'description' => $segment['description'], 'labels' => json_encode($segment['labels'], JSON_THROW_ON_ERROR)]);
                }
            });

            return 'ready_partial';
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            $lock->release();
        }
    }

    private function binary(string $name): string
    {
        $path = config('media-studio.intelligence.video.'.$name);
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_executable($path)) {
            throw new \RuntimeException('Video analysis decoder unavailable.');
        }

        return $path;
    }
}
