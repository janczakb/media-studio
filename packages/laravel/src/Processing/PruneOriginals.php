<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class PruneOriginals extends Command
{
    protected $signature = 'media-studio:originals:prune {workspace} {principal-type} {principal-id} {--limit=100} {--apply}';

    protected $description = 'Remove retired native image originals after their optimized master has been published.';

    public function handle(WorkspaceAccess $access): int
    {
        if (config('media-studio.processing.originals.keep', true) !== false) {
            $this->info('Original retention is enabled; nothing deleted.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            return self::INVALID;
        }
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        $identity = DB::table('fms_workspaces')->where('public_id', $this->argument('workspace'))->first();
        if (! $identity) {
            return self::FAILURE;
        }
        $tenant = TenantIdentity::fromWorkspace($identity);
        $workspace = $access->resolveForWorker($principal, $identity->public_id, $tenant, write: true, operation: 'files.process');
        $rows = DB::table('fms_original_retirements')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->where('delete_after', '<=', now())->orderBy('id')->limit($limit)->get();
        $removed = 0;
        foreach ($rows as $row) {
            if (! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($access, $principal, $tenant, $workspace, $row, &$removed): void {
                $access->resolveForWorker($principal, $workspace->public_id, $tenant, write: true, lock: true, operation: 'files.process');
                $entry = DB::table('fms_entries')->where('id', $row->entry_id)->lockForUpdate()->first();
                $prefix = trim($workspace->storage_root, '/').'/'.$workspace->public_id.'/originals/';
                if (! $entry || $entry->source_kind !== 'managed' || $entry->current_version_id === $row->version_id || ! str_starts_with($row->object_key, $prefix)) {
                    return;
                }
                // Never remove an object reused by another version/rendition or active archive.
                if (DB::table('fms_asset_versions')->where('disk', $row->disk)->where('object_key', $row->object_key)->where('id', '!=', $row->version_id)->exists()
                    || DB::table('fms_renditions')->where('disk', $row->disk)->where('object_key', $row->object_key)->exists()
                    || DB::table('fms_archive_jobs')->where('workspace_id', $workspace->id)->whereIn('state', ['queued', 'processing'])->where('expires_at', '>', now())->exists()) {
                    return;
                }
                $master = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
                if (! $master || ! Storage::disk($master->disk)->exists($master->object_key)) {
                    return;
                }
                $disk = Storage::disk($row->disk);
                if (! $disk->delete($row->object_key) && $disk->exists($row->object_key)) {
                    throw new \RuntimeException('Original deletion failed.');
                }
                DB::table('fms_original_retirements')->where('id', $row->id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
                $removed++;
            }, 3);
        }
        $this->info('Inspected '.$rows->count().'; removed '.$removed.' original files.');

        return self::SUCCESS;
    }
}
