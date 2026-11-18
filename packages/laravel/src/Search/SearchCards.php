<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;

final readonly class SearchCards
{
    public const COLUMNS = [
        'id', 'public_id', 'kind', 'name', 'normalized_name', 'parent_id', 'source_kind',
        'media_type', 'mime_type', 'size_bytes', 'current_version_id', 'created_at', 'updated_at',
        'revision', 'trashed_at', 'width', 'height', 'thumbnail_state', 'thumbnail_version_id',
        'thumbnail_key', 'preview_state', 'preview_version_id', 'preview_key',
    ];

    public function __construct(private Entries $entries, private SourcePolicy $sources) {}

    public function present(object $workspace, Principal $principal, array $rows): array
    {
        $parentIds = array_values(array_unique(array_filter(array_column($rows, 'parent_id'))));
        $parents = $parentIds === [] ? [] : $this->sources->visible($workspace, $principal,
            DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('id', $parentIds)
                ->where('kind', 'folder')->whereNull('trashed_at')->get(['id', 'name', 'source_kind'])
        );
        $names = array_column($parents, 'name', 'id');

        return array_map(function (object $row) use ($workspace, $names): array {
            $row->workspace_public_id = $workspace->public_id;

            return [...$this->entries->card($row), 'parentName' => $names[$row->parent_id] ?? null];
        }, $rows);
    }
}
