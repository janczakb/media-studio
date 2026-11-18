<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

/** Imports a bounded manifest page; never scans storage on a browse request. */
final class DiskIndexer
{
    public function importPage(Principal $principal, string $workspaceId, string $mountKey, array $paths, bool $apply = false, ?callable $beforePublish = null, ?callable $afterPublish = null): array
    {
        abort_unless(count($paths) >= 1 && count($paths) <= 120, 422);
        $mount = DiskMount::find($mountKey);
        abort_if($mount === null, 404);
        $workspace = $this->workspace($principal, $workspaceId);
        abort_unless($workspace->role === 'owner' || $workspace->role === 'admin', 403);
        abort_unless($mount->allows($workspace, $principal), 403);
        $disk = Storage::disk($mount->config['disk']);
        $records = [];
        foreach ($paths as $path) {
            abort_unless(is_string($path), 422);
        }
        foreach (array_unique($paths) as $path) {
            abort_unless(is_string($path), 422);
            $path = $mount->path($path);
            $name = basename($path);
            abort_unless(mb_strlen($name) <= 191, 422, 'The source filename is too long.');
            $metadata = DiskObjectMetadata::read($disk, $path);
            $mime = $metadata['mime'];
            abort_unless(strlen($mime) <= 160, 422);
            $records[] = ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => $metadata['size'], 'modified' => $metadata['modified']];
        }
        if (! $apply) {
            return ['inspected' => count($records), 'imported' => 0];
        }

        return DB::transaction(function () use ($principal, $workspaceId, $mount, $records, $beforePublish, $afterPublish): array {
            $workspace = $this->workspace($principal, $workspaceId, true);
            abort_unless(in_array($workspace->role, ['owner', 'admin'], true) && $mount->allows($workspace, $principal), 403);
            if ($beforePublish !== null) {
                $beforePublish();
            }
            $root = $this->folder($workspace, $mount, '', null);
            foreach ($records as $record) {
                $relative = substr($record['path'], strlen(trim((string) ($mount->config['prefix'] ?? ''), '/')));
                $parts = explode('/', ltrim($relative, '/'));
                array_pop($parts);
                $parent = $root;
                $directory = '';
                abort_unless(count($parts) <= 32, 422, 'The source directory is too deep.');
                foreach ($parts as $part) {
                    $directory .= ($directory === '' ? '' : '/').$part;
                    $parent = $this->folder($workspace, $mount, $directory, $parent, $part);
                }
                $this->file($workspace, $mount, $parent, $record);
            }

            if ($afterPublish !== null) {
                $afterPublish();
            }

            return ['inspected' => count($records), 'imported' => count($records)];
        }, 3);
    }

    private function workspace(Principal $principal, string $workspaceId, bool $lock = false): object
    {
        $identity = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first(['tenant_type', 'tenant_id', 'tenant_key']);
        abort_if($identity === null, 404);

        return app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, TenantIdentity::fromWorkspace($identity), write: true, lock: $lock, operation: 'sources.sync');
    }

    private function folder(object $workspace, DiskMount $mount, string $path, ?int $parent, ?string $label = null): int
    {
        $hash = hash('sha256', "folder\0".$path);
        $mapping = DB::table('fms_disk_sources')->where('workspace_id', $workspace->id)->where('mount_key', $mount->key)->where('path_hash', $hash)->first();
        if ($mapping !== null) {
            DB::table('fms_disk_sources')->where('id', $mapping->id)->update(['mount_revision' => $mount->revision()]);

            return (int) $mapping->entry_id;
        }
        $name = $label ?? ((string) ($mount->config['label'] ?? 'Existing files').' · '.$mount->key);
        abort_unless(mb_strlen($name) <= 191, 422);
        $entry = DB::table('fms_entries')->insertGetId(['public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'parent_id' => $parent, 'parent_scope' => $parent ?? 0, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'kind' => 'folder', 'sort_group' => 0, 'source_kind' => 'disk', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_disk_sources')->insert(['workspace_id' => $workspace->id, 'mount_key' => $mount->key, 'path_hash' => $hash, 'object_key' => $path, 'mount_revision' => $mount->revision(), 'entry_id' => $entry]);

        app(NavigationChanges::class)->record((int) $workspace->id, 'source', []);

        return $entry;
    }

    private function file(object $workspace, DiskMount $mount, int $parent, array $record): void
    {
        $hash = hash('sha256', "file\0".$record['path']);
        $mapping = DB::table('fms_disk_sources')->where('workspace_id', $workspace->id)->where('mount_key', $mount->key)->where('path_hash', $hash)->first();
        $entry = $mapping === null ? null : DB::table('fms_entries')->where('id', $mapping->entry_id)->first();
        if ($mapping !== null) {
            DB::table('fms_disk_sources')->where('id', $mapping->id)->update(['missing_since' => null, 'missing_at' => null, 'missing_checks' => 0]);
        }
        if ($mapping !== null && $mapping->mount_revision === $mount->revision() && (int) $mapping->modified_at === $record['modified'] && (int) $entry->size_bytes === $record['size']) {
            return;
        }
        $version = (string) Str::ulid();
        $values = ['parent_id' => $parent, 'parent_scope' => $parent, 'kind' => 'file', 'sort_group' => 1, 'name' => $record['name'], 'normalized_name' => mb_strtolower($record['name']), 'media_type' => substr(explode('/', $record['mime'])[0], 0, 24), 'mime_type' => $record['mime'], 'size_bytes' => $record['size'], 'source_kind' => 'disk', 'current_version_id' => $version, 'updated_at' => now(), 'thumbnail_state' => 'none', 'preview_state' => 'none'];
        if ($entry === null) {
            $id = DB::table('fms_entries')->insertGetId([...$values, 'public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'created_at' => now()]);
            DB::table('fms_disk_sources')->insert(['workspace_id' => $workspace->id, 'mount_key' => $mount->key, 'path_hash' => $hash, 'object_key' => $record['path'], 'mount_revision' => $mount->revision(), 'modified_at' => $record['modified'], 'entry_id' => $id]);
        } else {
            $id = $entry->id;
            DB::table('fms_entries')->where('id', $id)->update([...$values, 'revision' => DB::raw('revision + 1')]);
            DB::table('fms_disk_sources')->where('id', $mapping->id)->update(['mount_revision' => $mount->revision(), 'modified_at' => $record['modified']]);
        }
        DB::table('fms_asset_versions')->insert(['id' => $version, 'entry_id' => $id, 'disk' => $mount->config['disk'], 'object_key' => $record['path'], 'sha256' => null, 'size_bytes' => $record['size'], 'mime_type' => $record['mime'], 'created_at' => now()]);
        app(NavigationChanges::class)->record((int) $workspace->id, 'source', []);
    }
}
