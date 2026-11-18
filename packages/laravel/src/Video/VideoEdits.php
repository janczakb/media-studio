<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Video;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaCore\Video\VideoTrimProcessor;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Storage\LaravelObjectStore;

final class VideoEdits
{
    public function processor(): VideoTrimProcessor
    {
        $processor = app(config('media-studio.video_editing.processor', FfmpegTrimProcessor::class));
        if (! $processor instanceof VideoTrimProcessor) {
            throw new RuntimeException('Invalid video processor contract.');
        }

        return $processor;
    }

    private function minimumSelection(): float
    {
        return max(0.1, (float) config('media-studio.video_editing.min_selection_seconds', 3));
    }

    public function capabilities(object $workspace, object $entry, Principal $principal): array
    {
        $actor = app(StudioPermissions::class)->actor($principal);
        $allowed = $entry->media_type === 'video' && $entry->source_kind === 'managed' && $entry->trashed_at === null
            && app(StudioPermissions::class)->allows($actor, 'files.process', $workspace)
            && app(StudioPermissions::class)->allows($actor, 'files.update', $workspace);
        $mode = config('media-studio.video_editing.mode', 'queue');
        $connection = config('media-studio.video_editing.connection') ?: config('queue.default');
        $queueReady = $mode === 'queue' && config('queue.connections.'.$connection.'.driver', 'sync') !== 'sync';
        $sizeReady = (int) $entry->size_bytes <= (int) config('media-studio.video_editing.max_source_bytes', 268435456);
        $syncReady = $mode === 'sync' && (int) $entry->size_bytes <= (int) config('media-studio.video_editing.sync_max_bytes', 26214400);
        $enabled = config('media-studio.video_editing.enabled', false) && $allowed && $sizeReady && ($queueReady || $syncReady) && $this->processor()->available();

        return ['enabled' => (bool) $enabled, 'mode' => $mode, 'maxDuration' => (float) config('media-studio.video_editing.max_duration', 3600), 'minSelection' => $this->minimumSelection()];
    }

    public function create(object $workspace, object $entry, Principal $principal, array $input, string $key): object
    {
        abort_unless($this->capabilities($workspace, $entry, $principal)['enabled'], 422, 'Video trimming is unavailable for this file.');
        $row = DB::transaction(function () use ($workspace, $entry, $principal, $input, $key): object {
            $w = app(WorkspaceAccess::class)->resolve($principal, $workspace->public_id, write: true, lock: true);
            app(StudioPermissions::class)->authorize(app(StudioPermissions::class)->actor($principal), 'files.update', $w);
            $existing = DB::table('fms_video_edits')->where('workspace_id', $w->id)->where('request_key', hash('sha256', $principal->key().$key))->first();
            if ($existing) {
                abort_unless($existing->entry_id === $entry->id && $existing->source_revision === $input['revision'] && abs($existing->start_seconds - $input['start']) < 0.000001 && abs($existing->end_seconds - $input['end']) < 0.000001, 409, 'Idempotency key already used.');

                return $existing;
            }
            $current = app(Entries::class)->resolve($w->id, $entry->public_id);
            abort_unless($current->revision === $input['revision'] && $current->current_version_id === $entry->current_version_id && $current->trashed_at === null, 409, 'The file changed. Reload before trimming.');
            abort_if($this->minimumSelection() > $input['end'] - $input['start'] + 0.000001, 422, 'The selected clip is too short.');
            abort_if(DB::table('fms_video_edits')->where('entry_id', $current->id)->whereIn('state', ['queued', 'processing'])->exists(), 409, 'This file already has an active video edit.');
            abort_if(DB::table('fms_video_edits')->where('workspace_id', $w->id)->whereIn('state', ['queued', 'processing'])->count() >= (int) config('media-studio.video_editing.max_active', 4), 429, 'Too many video edits.');
            $id = (string) Str::ulid();
            DB::table('fms_video_edits')->insert(['id' => $id, 'workspace_id' => $w->id, 'entry_id' => $current->id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'request_key' => hash('sha256', $principal->key().$key), 'tenant_type' => $w->tenant_type, 'tenant_id' => $w->tenant_id, 'source_version' => $current->current_version_id, 'source_revision' => $current->revision, 'start_seconds' => $input['start'], 'end_seconds' => $input['end'], 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('fms_video_edits')->where('id', $id)->first();
        });
        if ($row->state !== 'queued') {
            return $row;
        }
        if (config('media-studio.video_editing.mode') === 'sync') {
            $this->run($row->id);
        } else {
            // Job identity is durable; duplicate delivery can only claim once.
            $job = (new TrimVideoJob($row->id))->onConnection(config('media-studio.video_editing.connection'))->onQueue(config('media-studio.video_editing.queue', 'video-edits'));
            try {
                dispatch($job);
            } catch (\Throwable $error) {
                DB::table('fms_video_edits')->where('id', $row->id)->where('state', 'queued')->update(['state' => 'failed', 'error' => 'dispatch_failed', 'finished_at' => now(), 'updated_at' => now()]);
                report($error);
            }
        }

        return DB::table('fms_video_edits')->where('id', $row->id)->first();
    }

    public function run(string $id): void
    {
        if (! DB::table('fms_video_edits')->where('id', $id)->where('state', 'queued')->update(['state' => 'processing', 'started_at' => now(), 'updated_at' => now()])) {
            return;
        }
        $edit = DB::table('fms_video_edits')->where('id', $id)->first();
        $source = null;
        $output = null;
        $objectKey = null;
        $disk = null;
        $published = false;
        try {
            $principal = new Principal($edit->principal_type, $edit->principal_id);
            $workspace = DB::table('fms_workspaces')->where('id', $edit->workspace_id)->first();
            if (! $workspace) {
                throw new RuntimeException('workspace_unavailable');
            }
            $tenant = $edit->tenant_type !== null ? new TenantIdentity($edit->tenant_type, $edit->tenant_id) : null;
            $w = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspace->public_id, $tenant, write: true, operation: 'files.process');
            app(StudioPermissions::class)->authorize(app(StudioPermissions::class)->actor($principal), 'files.update', $w);
            $entry = DB::table('fms_entries')->where('workspace_id', $w->id)->where('id', $edit->entry_id)->first();
            if (! $entry || $entry->source_kind !== 'managed' || $entry->trashed_at !== null || $entry->current_version_id !== $edit->source_version || $entry->revision !== $edit->source_revision) {
                throw new RuntimeException('source_changed');
            }
            $version = DB::table('fms_asset_versions')->where('id', $edit->source_version)->where('entry_id', $entry->id)->first();
            if (! $version) {
                throw new RuntimeException('source_changed');
            }
            $max = (int) config('media-studio.video_editing.max_source_bytes', 268435456);
            if (config('media-studio.video_editing.mode') === 'sync') {
                $max = min($max, (int) config('media-studio.video_editing.sync_max_bytes', 26214400));
            }
            if ($version->size_bytes > $max) {
                throw new RuntimeException('source_too_large');
            }
            $source = tmpfile();
            $output = tmpfile();
            if (! $source || ! $output) {
                throw new RuntimeException('temporary_storage_unavailable');
            }
            $in = Storage::disk($version->disk)->readStream($version->object_key);
            if (! is_resource($in)) {
                throw new RuntimeException('source_unavailable');
            }
            try {
                $copied = stream_copy_to_stream($in, $source, $max + 1);
            } finally {
                fclose($in);
            }
            if ($copied !== $version->size_bytes || $copied > $max) {
                throw new RuntimeException('source_too_large');
            }
            $sourcePath = stream_get_meta_data($source)['uri'];
            $outputPath = stream_get_meta_data($output)['uri'];
            if (! hash_equals($version->sha256, hash_file('sha256', $sourcePath))) {
                throw new RuntimeException('source_changed');
            }
            $processor = $this->processor();
            if (! $processor->available()) {
                throw new RuntimeException('processor_unavailable');
            }
            $info = $processor->inspect($sourcePath);
            $limit = (float) config('media-studio.video_editing.max_duration', 3600);
            if (config('media-studio.video_editing.mode') === 'sync') {
                $limit = min($limit, (float) config('media-studio.video_editing.sync_max_duration', 120));
            }
            if ($info['duration'] > $limit || $edit->start_seconds < 0 || $edit->end_seconds > $info['duration'] + 0.001 || $edit->end_seconds - $edit->start_seconds + 0.000001 < $this->minimumSelection()) {
                throw new RuntimeException('invalid_range');
            }
            $processor->trim($sourcePath, $outputPath, $edit->start_seconds, $edit->end_seconds);
            clearstatcache(true, $outputPath);
            $bytes = filesize($outputPath);
            $result = $processor->inspect($outputPath);
            if (! $bytes || $bytes > (int) config('media-studio.video_editing.max_output_bytes', 268435456) || abs($result['duration'] - ($edit->end_seconds - $edit->start_seconds)) > 0.3 || (new \finfo(FILEINFO_MIME_TYPE))->file($outputPath) !== 'video/mp4') {
                throw new RuntimeException('invalid_output');
            }
            $disk = Storage::disk($w->disk);
            $objectKey = trim($w->storage_root, '/').'/'.$w->public_id.'/originals/'.$edit->id;
            rewind($output);
            (new StreamObjectStore(new LaravelObjectStore($disk)))->write($objectKey, $output);
            DB::transaction(function () use ($principal, $workspace, $tenant, $entry, $edit, $version, $objectKey, $bytes, $outputPath, $result): void {
                $w = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspace->public_id, $tenant, write: true, lock: true, operation: 'files.process');
                app(StudioPermissions::class)->authorize(app(StudioPermissions::class)->actor($principal), 'files.update', $w);
                $current = DB::table('fms_entries')->where('id', $entry->id)->where('workspace_id', $w->id)->lockForUpdate()->first();
                if (! $current || $current->trashed_at !== null || $current->current_version_id !== $edit->source_version || $current->revision !== $edit->source_revision) {
                    throw new RuntimeException('source_changed');
                }
                DB::table('fms_asset_versions')->insert(['id' => $edit->id, 'entry_id' => $entry->id, 'disk' => $w->disk, 'object_key' => $objectKey, 'sha256' => hash_file('sha256', $outputPath), 'size_bytes' => $bytes, 'mime_type' => 'video/mp4', 'rendition_model' => $version->rendition_model, 'created_at' => now()]);
                $name = pathinfo($current->name, PATHINFO_FILENAME).'.mp4';
                DB::table('fms_entries')->where('id', $entry->id)->update(['name' => $name, 'normalized_name' => mb_strtolower($name), 'current_version_id' => $edit->id, 'mime_type' => 'video/mp4', 'size_bytes' => $bytes, 'width' => $result['width'], 'height' => $result['height'], 'revision' => $current->revision + 1, 'updated_at' => now()]);
                DB::table('fms_video_edits')->where('id', $edit->id)->update(['state' => 'ready', 'finished_at' => now(), 'updated_at' => now()]);
            });
            $published = true;
            try {
                app(ThumbnailProcessor::class)->schedule($w, DB::table('fms_entries')->where('id', $entry->id)->first(), $principal);
            } catch (\Throwable $error) {
                report($error);
            }
        } catch (\Throwable $error) {
            $code = in_array($error->getMessage(), ['source_changed', 'invalid_range', 'source_too_large', 'processor_unavailable', 'invalid_output'], true) ? $error->getMessage() : 'processing_failed';
            DB::table('fms_video_edits')->where('id', $id)->where('state', 'processing')->update(['state' => 'failed', 'error' => $code, 'finished_at' => now(), 'updated_at' => now()]);
            report($error);
        } finally {
            if (! $published && $disk && $objectKey && ! DB::table('fms_asset_versions')->where('id', $id)->exists()) {
                $disk->delete($objectKey);
            }
            if (is_resource($source)) {
                fclose($source);
            }if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    public function payload(object $edit): array
    {
        return ['id' => $edit->id, 'state' => $edit->state, 'error' => $edit->error, 'entryId' => DB::table('fms_entries')->where('id', $edit->entry_id)->value('public_id')];
    }
}
