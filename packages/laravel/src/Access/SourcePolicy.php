<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Spatie\SourceAccess;
use UseFlex\MediaStudio\Sources\DiskSourceAccess;

final class SourcePolicy
{
    /** @param iterable<object> $rows @return list<object> */
    public function visible(object $workspace, Principal $principal, iterable $rows): array
    {
        $items = is_array($rows) ? $rows : iterator_to_array($rows, false);
        if (($workspace->tree_operation_id ?? null) !== null && $items !== []) {
            $hidden = DB::table('fms_tree_operation_nodes as n')->join('fms_tree_operations as o', 'o.id', '=', 'n.operation_id')
                ->where('o.id', $workspace->tree_operation_id)->where('o.workspace_id', $workspace->id)->where('o.operation', 'trash')->where('o.state', 'preparing')
                ->whereIn('n.entry_id', array_column($items, 'id'))->pluck('n.entry_id')->all();
            $items = array_values(array_filter($items, fn ($row) => ! in_array($row->id, $hidden)));
        }
        if (config('media-studio.application_files.enabled') === true) {
            $items = array_values(array_filter($items, fn (object $row): bool => ($row->source_kind ?? 'managed') === 'managed'));
        }
        $items = app(DiskSourceAccess::class)->filterVisible($workspace, $principal, $items);
        foreach ($items as $row) {
            if (($row->source_kind ?? 'managed') === 'spatie') {
                return app(SourceAccess::class)->filterVisible($workspace, $principal, $items);
            }
        }

        return array_values($items);
    }

    public function readable(object $workspace, Principal $principal, object $entry): void
    {
        if (($entry->source_kind ?? 'managed') === 'disk') {
            abort_unless(app(DiskSourceAccess::class)->filterVisible($workspace, $principal, [$entry]) !== [], 404);
        }
        if (($entry->source_kind ?? 'managed') === 'spatie') {
            app(SourceAccess::class)->assertReadable($workspace, $principal, (int) $entry->id);
        }
    }

    public function original(object $workspace, Principal $principal, object $entry): void
    {
        if ($entry->source_kind === 'disk') {
            app(DiskSourceAccess::class)->original($workspace, $principal, $entry);
        }
        if ($entry->source_kind === 'spatie') {
            app(SourceAccess::class)->assertOriginal($workspace, $principal, $entry);
        }
    }

    public function thumbnailMime(object $workspace, Principal $principal, object $entry): string
    {
        return $entry->source_kind === 'spatie'
            ? app(SourceAccess::class)->thumbnailMime($workspace, $principal, $entry)
            : ($entry->thumbnail_mime_type ?? 'image/webp');
    }

    public function previewMime(object $workspace, Principal $principal, object $entry): string
    {
        return $entry->source_kind === 'spatie'
            ? app(SourceAccess::class)->previewMime($workspace, $principal, $entry)
            : (string) $entry->preview_mime_type;
    }

    public function managed(object $entry): void
    {
        abort_if(($entry->purging_at ?? null) !== null, 409, 'This item is being permanently removed.');
        abort_if(($entry->source_kind ?? 'managed') !== 'managed', 403, 'This item is owned by its configured source.');
    }
}
