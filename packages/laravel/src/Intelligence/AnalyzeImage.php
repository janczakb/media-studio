<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;

final class AnalyzeImage
{
    public function run(Principal $actor, string $workspaceId, string $entryId, ?TenantIdentity $tenant): string
    {
        $profile = app(AnalysisProfile::class)->image();
        $workspace = app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
        $entry = app(Entries::class)->resolve($workspace->id, $entryId);
        abort_unless($entry->source_kind === 'managed' && $entry->trashed_at === null && $entry->media_type === 'image', 403);
        $version = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->where('id', $entry->current_version_id)->first();
        if ($version === null) {
            return 'source_required';
        }
        $source = app(AnalysisSource::class)->resolve($workspace, $entry, $version, $actor);
        if ($source === null) {
            return config('media-studio.intelligence.input.missing') === 'skip' ? 'skipped_input' : 'waiting_input';
        }
        $id = hash('sha256', json_encode([$workspace->id, $entry->id, $entry->current_version_id, $source->disk, $source->object_key, $source->sha256 ?? null, 'prepared-jpeg-v2', $profile['provider'], $profile['model'] ?? null, $profile['region'] ?? null, $profile['max_labels'], 'image-analysis-v1'], JSON_THROW_ON_ERROR));
        $lock = Cache::store(config('media-studio.intelligence.cache_store'))->lock('fms:analysis:'.$id, 120);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            if (DB::table('fms_image_analyses')->where('id', $id)->exists()) {
                return 'ready';
            }
            $bytes = app(AnalysisImage::class)->prepare($source);
            $mime = 'image/jpeg';
            $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) > 1048576 || $size === false || ($size['mime'] ?? null) !== $mime || $size[0] * $size[1] > 1048576 || max($size[0], $size[1]) > 1024) {
                throw new \RuntimeException('Analysis derivative exceeds image bounds.');
            }
            app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
            abort_unless(DB::table('fms_entries')->where('id', $entry->id)->where('current_version_id', $entry->current_version_id)->whereNull('trashed_at')->exists(), 409);
            $result = app($profile['adapter'])->analyze($bytes, $mime, $profile);

            return DB::transaction(function () use ($actor, $workspaceId, $tenant, $entryId, $entry, $id, $profile, $result): string {
                $workspace = app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, lock: true, operation: 'files.process');
                $current = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $entryId)->lockForUpdate()->first();
                abort_unless($current !== null && $current->source_kind === 'managed' && $current->trashed_at === null && $current->current_version_id === $entry->current_version_id, 409);
                DB::table('fms_image_analyses')->insert(['id' => $id, 'entry_id' => $entry->id, 'version_id' => $entry->current_version_id, 'provider' => $profile['provider'], 'description' => $result->description, 'created_at' => now()]);
                if ($result->labels !== []) {
                    DB::table('fms_ai_labels')->insert(array_map(static fn ($label): array => ['analysis_id' => $id, 'name' => $label['name'], 'normalized_name' => mb_strtolower($label['name'], 'UTF-8'), 'score' => $label['score']], $result->labels));
                }

                return 'ready';
            });
        } finally {
            $lock->release();
        }
    }
}
