<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskMount;

/** Grants derivative writes only; never grants mutation of an external original. */
final class RenditionSource
{
    public function authorize(object $workspace, Principal $principal, object $entry): void
    {
        if (($entry->source_kind ?? 'managed') !== 'disk') {
            app(SourcePolicy::class)->managed($entry);

            return;
        }
        abort_if(($entry->purging_at ?? null) !== null, 409);
        abort_unless($entry->kind === 'file' && in_array($entry->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true), 403);
        $mapping = DB::table('fms_disk_sources')->where('workspace_id', $workspace->id)->where('entry_id', $entry->id)->first();
        $mount = $mapping === null ? null : DiskMount::find($mapping->mount_key);
        abort_unless($mount !== null && ($mount->config['renditions'] ?? false) === true, 403, 'Generated source renditions are disabled.');
        app(SourcePolicy::class)->readable($workspace, $principal, $entry);
    }

    public function verify(object $workspace, Principal $principal, object $version): void
    {
        if (($version->source_kind ?? 'managed') !== 'disk') {
            return;
        }
        $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $version->entry_id)->where('current_version_id', $version->id)->whereNull('trashed_at')->first();
        abort_if($entry === null, 409, 'The indexed source version changed.');
        $this->authorize($workspace, $principal, $entry);
        $entry->disk = $version->disk;
        $entry->object_key = $version->object_key;
        app(SourcePolicy::class)->original($workspace, $principal, $entry);
    }
}
