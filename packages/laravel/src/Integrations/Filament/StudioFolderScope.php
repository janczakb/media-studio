<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Navigation\AncestorReader;

final class StudioFolderScope
{
    /** Verify selected assets using current server hierarchy. */
    public static function assert(string $workspace, array $files, ?string $scope): void
    {
        if ($scope === null || $files === []) {
            return;
        }
        $resolved = app(WorkspaceAccess::class)->resolve(StudioPicker::principal(), $workspace);
        $ids = array_map(static fn (array $file): string => $file['assetId'] ?? $file['id'], $files);
        $parents = DB::table('fms_entries')->where('workspace_id', $resolved->id)
            ->whereIn('public_id', $ids)->distinct()->pluck('parent_id')->all();
        self::assertParents($resolved->id, $parents, $scope);
    }

    public static function assertParents(int $workspace, array $parents, ?string $scope): void
    {
        if ($scope === null) {
            return;
        }
        $root = app(Entries::class)->parentId($workspace, $scope);
        $checked = [$root => true];
        $budget = max(100, min(100000, (int) config('media-studio.navigation.verification_nodes', 20000)));
        foreach ($parents as $parent) {
            self::assertInsideFolder($workspace, $parent === null ? null : (int) $parent, $checked, $budget);
        }
    }

    private static function assertInsideFolder(int $workspace, ?int $parent, array &$checked, int &$budget): void
    {
        $reader = app(AncestorReader::class);
        $visited = [];
        while ($parent !== null && $budget > 0) {
            if (isset($checked[$parent])) {
                $checked += $visited;

                return;
            }
            $rows = $reader->page($workspace, $parent, min(100, $budget));
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if (isset($checked[$id])) {
                    $checked += $visited;

                    return;
                }
                if ($row->trashed_at !== null || $row->kind !== 'folder' || isset($visited[$id])) {
                    break 2;
                }
                $visited[$id] = true;
                $budget--;
                $parent = $row->parent_id === null ? null : (int) $row->parent_id;
            }
            if ($parent !== null && isset($visited[$parent])) {
                break;
            }
        }

        throw ValidationException::withMessages(['references' => __('media-studio::editor.folder_scope')]);
    }
}
