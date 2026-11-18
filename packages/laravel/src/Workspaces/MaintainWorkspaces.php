<?php

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MaintainWorkspaces extends Command
{
    protected $signature = 'media-studio:workspaces:maintain';

    protected $description = 'Dispatch bounded resumable workspace retirement steps.';

    public function handle(WorkspaceRetirement $service): int
    {
        foreach (DB::table('fms_workspace_operations')->whereIn('state', ['queued', 'working'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('id')->limit(10)->pluck('id') as $id) {
            $service->dispatch($id);
        }

        return self::SUCCESS;
    }
}
