<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

use Illuminate\Support\Facades\DB;
use LogicException;

/** A transactional journal. Writers must already own the workspace write lock. */
final class NavigationChanges
{
    /** @param iterable<object> $rows @param array<int, int|null> $previousParents */
    public function entries(int $workspace, string $kind, iterable $rows, array $previousParents = []): int
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        if (count($rows) > 100) {
            throw new LogicException('A navigation delta is limited to 100 entries.');
        }
        $parents = array_values(array_unique(array_filter([...array_column($rows, 'parent_id'), ...array_values($previousParents)], static fn ($id) => $id !== null)));
        $public = $parents === [] ? [] : DB::table('fms_entries')->where('workspace_id', $workspace)->whereIn('id', $parents)->pluck('public_id', 'id')->all();
        $nodes = array_map(static fn (object $row): array => [
            'id' => $row->public_id, 'kind' => $row->kind, 'name' => $row->name,
            'revision' => (int) $row->revision, 'trashedAt' => $row->trashed_at,
            'parentId' => $row->parent_id === null ? null : ($public[$row->parent_id] ?? null),
            'previousParentId' => ($previousParents[$row->id] ?? null) === null ? null : ($public[$previousParents[$row->id]] ?? null),
        ], $rows);

        return $this->record($workspace, $kind, ['nodes' => $nodes]);
    }

    public function record(int $workspace, string $kind, array $payload): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Navigation changes must be recorded inside the writer transaction.');
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 262144) {
            throw new LogicException('A navigation delta exceeds its payload budget.');
        }
        DB::table('fms_workspaces')->where('id', $workspace)->increment('navigation_revision');
        $revision = (int) DB::table('fms_workspaces')->where('id', $workspace)->value('navigation_revision');
        DB::table('fms_navigation_changes')->insert([
            'workspace_id' => $workspace, 'revision' => $revision, 'kind' => $kind,
            'payload' => $encoded, 'created_at' => now(),
        ]);

        return $revision;
    }
}
