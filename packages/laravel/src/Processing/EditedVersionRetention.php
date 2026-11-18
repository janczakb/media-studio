<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class EditedVersionRetention
{
    private const TABLE = 'fms_edited_version_retirements';

    /** Called in the same locked transaction that publishes the new current version. */
    public function retire(object $workspace, object $entry, object $previous, bool $manual = false): void
    {
        if ((! $manual && config('media-studio.edited_versions.keep_history', true) !== false) || $entry->source_kind !== 'managed') {
            return;
        }

        DB::table(self::TABLE)->insertOrIgnore([
            'version_id' => $previous->id, 'workspace_id' => $workspace->id, 'entry_id' => $entry->id, 'due_at' => now(), 'manual' => $manual,
        ]);
    }

    /** @return array{removed:int, failed:int} */
    public function maintain(int $limit): array
    {
        $removed = $failed = 0;
        $rows = DB::table(self::TABLE)->where('due_at', '<=', now())
            // Still finish storage cleanup for versions detached before history was re-enabled.
            ->when(config('media-studio.edited_versions.keep_history', true) !== false, fn ($query) => $query
                ->where(fn ($query) => $query->where('manual', true)->orWhereNotNull('objects')))
            ->orderBy('due_at')->orderBy('version_id')->limit($limit)->get();

        foreach ($rows as $row) {
            try {
                $removed += (int) $this->process($row);
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return compact('removed', 'failed');
    }

    /** Only the retirement belonging to this saved edit is processed synchronously. */
    public function removeNow(string $versionId): bool
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->removeNow($versionId));

            return false;
        }

        try {
            $row = DB::table(self::TABLE)->where('version_id', $versionId)->where('manual', true)->first();

            return $row !== null && $this->process($row);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function process(object $row): bool
    {
        if (DB::table(self::TABLE)->where('version_id', $row->version_id)->where('due_at', '<=', now())
            ->update(['due_at' => now()->addMinutes(5)]) !== 1) {
            return false;
        }

        $objects = $row->objects === null
            ? $this->detach($row)
            : json_decode($row->objects, true, 512, JSON_THROW_ON_ERROR);
        if ($objects === null) {
            return false;
        }

        $shared = $this->sharedObjects($objects);
        foreach ($objects as $object) {
            if (isset($shared[$object['disk']][$object['key']])) {
                continue;
            }
            $disk = Storage::disk($object['disk']);
            if (! $disk->delete($object['key']) && $disk->exists($object['key'])) {
                throw new RuntimeException('Edited version storage cleanup failed.');
            }
        }

        return DB::table(self::TABLE)->where('version_id', $row->version_id)->delete() === 1;
    }

    /** @return list<array{disk:string,key:string}>|null */
    private function detach(object $retirement): ?array
    {
        $current = DB::table('fms_entries as e')->join('fms_asset_versions as v', 'v.id', '=', 'e.current_version_id')
            ->where('e.id', $retirement->entry_id)->where('e.workspace_id', $retirement->workspace_id)
            ->whereColumn('v.entry_id', 'e.id')->first(['v.id', 'v.disk', 'v.object_key']);
        $currentIsReadable = $current !== null && Storage::disk($current->disk)->exists($current->object_key);

        return DB::transaction(function () use ($retirement, $current, $currentIsReadable): ?array {
            // Usage registration and Studio mutations serialize on this same workspace row.
            $workspace = DB::table('fms_workspaces')->where('id', $retirement->workspace_id)->lockForUpdate()->first();
            $entry = DB::table('fms_entries')->where('id', $retirement->entry_id)->where('workspace_id', $retirement->workspace_id)->lockForUpdate()->first();
            $version = DB::table('fms_asset_versions')->where('id', $retirement->version_id)->where('entry_id', $retirement->entry_id)->first();
            if ($entry === null || $version === null) {
                return [];
            }
            if (! $currentIsReadable || $entry->current_version_id !== $current->id
                || $workspace === null || $workspace->retirement_id !== null || $workspace->encryption_operation !== null
                || $entry->source_kind !== 'managed' || $entry->trashed_at !== null || $entry->current_version_id === $version->id
                || $entry->thumbnail_version_id === $version->id || $entry->preview_version_id === $version->id
                || DB::table('fms_usages')->where('entry_id', $entry->id)->where('version_id', $version->id)->exists()
                || DB::table('fms_comments')->where('entry_id', $entry->id)->where('anchor_version_id', $version->id)->exists()
                || DB::table('fms_archive_jobs')->where('workspace_id', $workspace->id)->whereIn('state', ['queued', 'processing'])
                    ->where('expires_at', '>', now())->exists()
                || DB::table('fms_renditions')->where('version_id', $version->id)->where('state', 'processing')
                    ->where('lease_expires_at', '>', now())->exists()) {
                $this->defer($version->id);

                return null;
            }

            $renditions = DB::table('fms_renditions')->where('entry_id', $entry->id)->where('version_id', $version->id)
                ->where('owned', true)->whereNotNull('object_key')->limit(257)->get();
            if ($renditions->count() > 256) {
                $this->defer($version->id);

                return null;
            }

            $prefix = trim($workspace->storage_root, '/').'/'.$workspace->public_id;
            $objects = [];
            foreach ([$version, ...$renditions] as $item) {
                $key = $item->object_key;
                $nativeRendition = is_string($key) && str_starts_with($key, $prefix.'/renditions/') && preg_match(
                    '/\A[0-9A-HJKMNP-TV-Z]{26}\/[a-f0-9]{32}\/[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/',
                    substr($key, strlen($prefix.'/renditions/')),
                );
                if (! is_string($item->disk) || ($key !== $prefix.'/originals/'.$version->id && ! $nativeRendition)) {
                    $this->defer($version->id);

                    return null;
                }
                $objects[$item->disk."\0".$key] = ['disk' => $item->disk, 'key' => $key];
            }

            $objects = array_values($objects);
            DB::table(self::TABLE)->where('version_id', $version->id)->update(['objects' => json_encode($objects, JSON_THROW_ON_ERROR)]);
            DB::table('fms_renditions')->where('entry_id', $entry->id)->where('version_id', $version->id)->delete();
            DB::table('fms_asset_versions')->where('id', $version->id)->where('entry_id', $entry->id)->delete();

            return $objects;
        }, 3);
    }

    private function defer(string $version): void
    {
        DB::table(self::TABLE)->where('version_id', $version)->update(['due_at' => now()->addHour()]);
    }

    /**
     * @param  list<array{disk:string,key:string}>  $objects
     * @return array<string, array<string, true>>
     */
    private function sharedObjects(array $objects): array
    {
        if ($objects === []) {
            return [];
        }

        $keys = array_unique(array_column($objects, 'key'));
        $disks = array_unique(array_column($objects, 'disk'));
        $shared = [];
        foreach (['fms_asset_versions', 'fms_renditions'] as $table) {
            foreach (DB::table($table)->whereIn('disk', $disks)->whereIn('object_key', $keys)->distinct()->get(['disk', 'object_key']) as $row) {
                $shared[$row->disk][$row->object_key] = true;
            }
        }
        foreach (['thumbnail', 'preview'] as $role) {
            $disk = $role.'_disk';
            $key = $role.'_key';
            foreach (DB::table('fms_entries')->whereIn($disk, $disks)->whereIn($key, $keys)->distinct()->get([$disk, $key]) as $row) {
                $shared[$row->{$disk}][$row->{$key}] = true;
            }
        }

        return $shared;
    }
}
