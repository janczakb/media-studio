<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Runs in the same workspace transaction as trashing. Links never revive on restore. */
final class TrashShares
{
    public function remove(int $workspace, Builder $entries): void
    {
        $shares = DB::table('fms_share_entries')->whereIn('entry_id', $entries)->select('share_id');
        $this->removeQuery($workspace, $shares);
    }

    public function removeLegacy(int $limit = 100): void
    {
        $ids = DB::table('fms_shares')->whereExists(fn ($q) => $q->selectRaw('1')->from('fms_share_entries as se')->join('fms_entries as e', 'e.id', '=', 'se.entry_id')->whereColumn('se.share_id', 'fms_shares.id')->whereNotNull('e.trashed_at'))->orderBy('id')->limit(min(100, max(1, $limit)))->get(['id', 'workspace_id']);
        foreach ($ids->groupBy('workspace_id') as $workspace => $shares) {
            DB::transaction(fn () => $this->removeQuery((int) $workspace, DB::table('fms_share_entries')->whereIn('share_id', $shares->pluck('id'))->select('share_id')), 3);
        }
    }

    private function removeQuery(int $workspace, Builder $shares): void
    {
        // Preserve object receipts for asynchronous storage cleanup instead of cascading them away.
        DB::table('fms_archive_jobs')->where('workspace_id', $workspace)->whereIn('share_id', $shares)
            ->update(['share_id' => null, 'state' => 'failed', 'expires_at' => now(), 'updated_at' => now()]);
        DB::table('fms_shares')->where('workspace_id', $workspace)->whereIn('id', $shares)->delete();
    }
}
