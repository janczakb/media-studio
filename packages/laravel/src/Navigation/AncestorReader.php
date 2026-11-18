<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

use Illuminate\Support\Facades\DB;

/** Bounded recursive work, portable across SQLite, PostgreSQL and MySQL 8+. */
final class AncestorReader
{
    /** @return list<object> Nearest first, including the requested folder. */
    public function page(int $workspace, int $start, int $limit): array
    {
        $limit = max(1, min(101, $limit));
        $table = DB::connection()->getQueryGrammar()->wrapTable('fms_entries');

        return DB::select("WITH RECURSIVE trail AS (
            SELECT id, public_id, parent_id, name, kind, source_kind, trashed_at, revision, 0 AS distance
            FROM {$table} WHERE workspace_id = ? AND id = ?
            UNION ALL
            SELECT n.id, n.public_id, n.parent_id, n.name, n.kind, n.source_kind, n.trashed_at, n.revision, t.distance + 1
            FROM {$table} n INNER JOIN trail t ON n.id = t.parent_id
            WHERE n.workspace_id = ? AND t.distance < ?
        ) SELECT * FROM trail ORDER BY distance", [$workspace, $start, $workspace, $limit - 1]);
    }

    /** Complete verification in bounded SQL batches. Exhaustion always fails closed. */
    public function assertDestination(int $workspace, array $moving, ?int $parent): void
    {
        $seen = array_fill_keys($moving, true);
        $budget = max(100, min(100000, (int) config('media-studio.navigation.verification_nodes', 20000)));
        $deadline = hrtime(true) + max(100, min(10000, (int) config('media-studio.navigation.verification_ms', 2000))) * 1000000;
        $visited = 0;
        while ($parent !== null) {
            abort_if(hrtime(true) >= $deadline || $visited >= $budget, 409, 'The destination could not be completely verified. No changes were made.');
            $rows = $this->page($workspace, $parent, min(100, $budget - $visited));
            abort_if($rows === [], 409, 'The destination hierarchy is incomplete.');
            foreach ($rows as $row) {
                $id = (int) $row->id;
                abort_if(isset($seen[$id]), 422, 'A folder cannot be moved into itself or its descendants.');
                abort_unless($row->kind === 'folder' && $row->trashed_at === null, 409, 'The destination hierarchy is unavailable.');
                $seen[$id] = true;
                $visited++;
                $parent = $row->parent_id === null ? null : (int) $row->parent_id;
            }
        }
    }
}
