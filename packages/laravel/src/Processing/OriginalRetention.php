<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called under the existing workspace/version publication lock. */
final class OriginalRetention
{
    public function promote(object $workspace, object $entry, object $version): void
    {
        if (config('media-studio.processing.originals.keep', true) !== false || $entry->source_kind !== 'managed' || ! str_starts_with($version->mime_type, 'image/')) {
            return;
        }
        if (! str_starts_with($version->object_key, trim($workspace->storage_root, '/').'/'.$workspace->public_id.'/originals/')) {
            return;
        }
        if (DB::table('fms_original_retirements')->where('version_id', $version->id)->exists()) {
            return;
        }
        if (DB::table('fms_renditions')->where('version_id', $version->id)->where('owned', true)->where('state', '!=', 'ready')->exists()) {
            return;
        }
        $large = DB::table('fms_renditions')->where('version_id', $version->id)->where('owned', true)->where('state', 'ready')
            ->where('preset', config('media-studio.processing.originals.replacement_preset', 'large-v1'))->first();
        if (! $large || ! in_array($large->mime_type, ['image/avif', 'image/webp'], true)) {
            return;
        }
        // Publish a new immutable version; never change the bytes behind an existing version ID.
        $next = (string) Str::ulid();
        DB::table('fms_asset_versions')->insert([
            'id' => $next, 'entry_id' => $entry->id, 'disk' => $large->disk, 'object_key' => $large->object_key,
            'sha256' => $large->sha256, 'size_bytes' => $large->size_bytes, 'mime_type' => $large->mime_type,
            'rendition_model' => $version->rendition_model, 'created_at' => now(),
        ]);
        foreach (DB::table('fms_renditions')->where('version_id', $version->id)->get() as $rendition) {
            $values = (array) $rendition;
            unset($values['id']);
            $values['version_id'] = $next;
            DB::table('fms_renditions')->insert($values);
        }
        $current = DB::table('fms_entries')->where('id', $entry->id)->first();
        $name = pathinfo($current->name, PATHINFO_FILENAME).'.'.substr($large->mime_type, 6);
        $normalized = mb_strtolower($name, 'UTF-8');
        if (DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_scope', $current->parent_scope)->where('normalized_name', $normalized)->whereNull('trashed_at')->where('id', '!=', $entry->id)->exists()) {
            $name = pathinfo($current->name, PATHINFO_FILENAME).'-'.strtolower(substr($next, -6)).'.'.substr($large->mime_type, 6);
            $normalized = mb_strtolower($name, 'UTF-8');
        }
        $changes = ['name' => $name, 'normalized_name' => $normalized, 'current_version_id' => $next, 'mime_type' => $large->mime_type, 'size_bytes' => $large->size_bytes, 'width' => $large->width, 'height' => $large->height, 'revision' => $current->revision + 1, 'updated_at' => now()];
        foreach (['thumbnail', 'preview'] as $role) {
            if ($current->{$role.'_version_id'} === $version->id) {
                $changes[$role.'_version_id'] = $next;
            }
        }
        DB::table('fms_entries')->where('id', $entry->id)->where('current_version_id', $version->id)->update($changes);
        // Converted masters are never admitted to the image conversion pipeline again automatically.
        DB::table('fms_original_retirements')->insert([
            'workspace_id' => $workspace->id, 'entry_id' => $entry->id, 'version_id' => $version->id,
            'disk' => $version->disk, 'object_key' => $version->object_key, 'sha256' => $version->sha256,
            'delete_after' => now()->addSeconds(max(3600, (int) config('media-studio.processing.originals.grace_seconds', 86400))),
        ]);
    }
}
