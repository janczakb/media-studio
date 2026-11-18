<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Trash\TrashShares;

final class BulkEntries
{
    public const MAX_ITEMS = 100;

    public function __construct(
        private WorkspaceAccess $access,
        private Entries $entries,
        private EntryOperations $operations,
        private SourcePolicy $sources,
    ) {}

    /**
     * @param  list<array{id: string, revision: int}>  $items
     * @return list<object>
     */
    public function execute(Principal $principal, string $workspaceId, string $operation, array $items, ?string $parent = null): array
    {
        abort_unless(in_array($operation, ['move', 'trash', 'restore'], true), 422, 'Unsupported bulk operation.');
        abort_if($parent !== null && ($operation !== 'move' || ! Str::isUlid($parent)), 422, 'Invalid bulk destination.');
        abort_unless(array_is_list($items) && count($items) >= 1 && count($items) <= self::MAX_ITEMS, 422, 'Select between 1 and 100 items.');

        $revisions = [];
        foreach ($items as $item) {
            abort_unless(is_array($item) && is_string($item['id'] ?? null) && Str::isUlid($item['id'])
                && is_int($item['revision'] ?? null) && $item['revision'] > 0, 422, 'Each item needs a valid ID and revision.');
            $id = strtoupper($item['id']);
            abort_if(isset($revisions[$id]), 422, 'An item can appear only once in a bulk operation.');
            $revisions[$id] = $item['revision'];
        }

        try {
            return DB::transaction(function () use ($principal, $workspaceId, $operation, $revisions, $parent): array {
                $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
                $scope = DB::table('fms_entries')->where('workspace_id', $workspace->id);
                $rows = (clone $scope)->whereIn('public_id', array_keys($revisions))->orderBy('id')->lockForUpdate()->get();
                abort_unless($rows->count() === count($revisions), 404);
                foreach ($rows as $row) {
                    $this->sources->managed($row);
                    abort_unless((int) $row->revision === $revisions[$row->public_id], 409, 'An item changed in another session. Refresh before saving.');
                    if ($operation === 'restore') {
                        abort_if($row->trashed_at === null, 409, 'An item is not in the trash.');
                    } else {
                        abort_if($row->trashed_at !== null, 409, 'Restore trashed items before editing them.');
                    }
                }

                $ids = $rows->pluck('id')->all();
                $changes = ['updated_at' => now()];
                if ($operation === 'move') {
                    $parentId = $this->entries->parentId($workspace->id, $parent === null ? null : strtoupper($parent), write: true);
                    $this->operations->assertNoCycle($workspace->id, $ids, $parentId);
                    $changes += ['parent_id' => $parentId, 'parent_scope' => $parentId ?? 0];
                } elseif ($operation === 'trash') {
                    abort_if(DB::table('fms_usages')->whereIn('entry_id', $ids)->exists(), 409, 'An item is still used in the application. Remove its references first.');
                    $folders = $rows->where('kind', 'folder')->pluck('id')->all();
                    abort_if($folders !== [] && (clone $scope)->whereIn('parent_scope', $folders)->exists(), 409, 'Move the contents before removing a folder.');
                    $changes['trashed_at'] = now();
                    app(TrashShares::class)->remove((int) $workspace->id, DB::table('fms_entries')->whereIn('id', $ids)->select('id'));
                } else {
                    $parents = $rows->pluck('parent_id')->filter(fn ($id): bool => $id !== null)->unique()->all();
                    abort_if($parents !== [] && (clone $scope)->whereIn('id', $parents)->whereNull('trashed_at')->count() !== count($parents), 409, 'An original folder is unavailable.');
                    $changes['trashed_at'] = null;
                }

                $updated = (clone $scope)->whereIn('id', $ids)->increment('revision', 1, $changes);
                abort_unless($updated === count($ids), 409, 'An item changed in another session.');
                $fresh = $scope->whereIn('id', $ids)->get()->keyBy('public_id');
                app(NavigationChanges::class)->entries((int) $workspace->id, $operation, $fresh, $rows->pluck('parent_id', 'id')->all());

                if (in_array($operation, ['trash', 'restore'], true)) {
                    app(ActivityLog::class)->recordMany($workspace, $principal, $fresh, $operation === 'trash' ? 'trashed' : 'restored');
                }

                return array_map(static function (string $id) use ($fresh, $workspaceId): object {
                    $row = $fresh[$id];
                    $row->workspace_public_id = $workspaceId;

                    return $row;
                }, array_keys($revisions));
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'An item with this name already exists in the destination folder.');
        }
    }
}
