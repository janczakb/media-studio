<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use UseFlex\MediaStudio\Identity\Principal;

/** A bounded scheduler tick; also works with the sync queue on shared hosting. */
final class MaintainTrash extends Command
{
    protected $signature = 'media-studio:trash:maintain';

    protected $description = 'Resume folder operations and optionally prune expired trash in bounded batches.';

    public function handle(TreeOperations $trees, TrashRetention $retention): int
    {
        foreach (DB::table('fms_tree_operations')->where('state', 'preparing')->orderBy('updated_at')->orderBy('id')->limit(10)->pluck('id') as $id) {
            try {
                $trees->advanceSaved($id);
            } catch (Throwable $error) {
                report($error);
            }
        }
        app(TrashShares::class)->removeLegacy();
        $remaining = 1000;
        foreach (DB::table('fms_tree_operations')->whereIn('state', ['completed', 'failed', 'cancelled'])->whereExists(fn ($q) => $q->selectRaw('1')->from('fms_tree_operation_nodes as n')->whereColumn('n.operation_id', 'fms_tree_operations.id'))->orderBy('updated_at')->orderBy('id')->limit(5)->pluck('id') as $id) {
            $members = DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->orderBy('entry_id')->limit($remaining)->pluck('entry_id');
            $remaining -= DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->whereIn('entry_id', $members)->delete();
            if ($remaining <= 0) {
                break;
            }
        }
        if (! config('media-studio.trash.retention_enabled', false)) {
            return self::SUCCESS;
        }
        // Rotate workspaces across ticks; one large tenant cannot monopolize admission.
        $key = 'fms.trash.workspace-cursor';
        $after = (int) Cache::get($key, 0);
        $workspaces = DB::table('fms_workspaces')->where('id', '>', $after)->whereNull('tree_operation_id')->whereNull('retirement_id')->orderBy('id')->limit(5)->get();
        foreach ($workspaces as $workspace) {
            $owner = DB::table('fms_memberships')->where('workspace_id', $workspace->id)->where('role', 'owner')->orderBy('id')->first();
            if ($owner === null) {
                continue;
            }
            $principal = new Principal($owner->principal_type, $owner->principal_id);
            try {
                $retention->workspace($principal, $workspace->public_id);
                $pending = DB::table('fms_trash_purges')->where('workspace_id', $workspace->id)->whereIn('state', ['queued', 'failed', 'processing'])->where('attempts', '<', $retention->maxAttempts())->orderBy('updated_at')->limit(10)->pluck('id');
                foreach ($pending as $id) {
                    $retention->dispatch($id);
                }
                foreach ($retention->candidates($workspace, 10)->get(['public_id']) as $entry) {
                    try {
                        $retention->dispatch($retention->schedule($principal, $workspace->public_id, $entry->public_id));
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
            } catch (Throwable $error) {
                report($error);
            }
        }
        Cache::put($key, $workspaces->count() < 5 ? 0 : $workspaces->last()->id, now()->addDay());

        return self::SUCCESS;
    }
}
