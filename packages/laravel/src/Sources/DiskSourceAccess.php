<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Identity\Principal;

final class DiskSourceAccess
{
    public function filterVisible(object $workspace, Principal $principal, array $rows): array
    {
        $external = array_filter($rows, fn ($row) => ($row->source_kind ?? 'managed') === 'disk');
        if ($external === []) {
            return $rows;
        }
        $mappings = DB::table('fms_disk_sources')->where('workspace_id', $workspace->id)->whereIn('entry_id', array_column($external, 'id'))->whereNull('missing_at')->get()->groupBy('mount_key');
        $allowed = [];
        foreach ($mappings as $key => $members) {
            $mount = DiskMount::find((string) $key);
            if ($mount !== null && $mount->allows($workspace, $principal)) {
                foreach ($members as $member) {
                    if ($member->mount_revision === $mount->revision()) {
                        $allowed[$member->entry_id] = true;
                    }
                }
            }
        }

        return array_values(array_filter($rows, fn ($row) => ($row->source_kind ?? 'managed') !== 'disk' || isset($allowed[$row->id])));
    }

    public function original(object $workspace, Principal $principal, object $entry): void
    {
        abort_unless($this->filterVisible($workspace, $principal, [$entry]) !== [], 404);
        $mapping = DB::table('fms_disk_sources')->where('workspace_id', $workspace->id)->where('entry_id', $entry->id)->first();
        abort_if($mapping === null, 404);
        $mount = DiskMount::find($mapping->mount_key);
        abort_if($mount === null, 404);
        abort_unless($mount->config['disk'] === $entry->disk && $mount->path($entry->object_key) === $mapping->object_key, 404);
        $disk = Storage::disk($entry->disk);
        // Historical mutable disk paths must never masquerade as immutable versions.
        $current = DB::table('fms_entries')->where('id', $entry->id)->value('current_version_id');
        abort_unless($current === $entry->current_version_id, 404);
        $metadata = DiskObjectMetadata::read($disk, $entry->object_key);
        abort_unless($metadata['size'] === (int) $entry->size_bytes && $metadata['modified'] === (int) $mapping->modified_at, 404);
    }
}
