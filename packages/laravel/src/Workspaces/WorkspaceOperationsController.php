<?php

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class WorkspaceOperationsController
{
    public function store(Request $request, string $workspace, WorkspaceRetirement $service)
    {
        $v = $request->validate(['confirmName' => ['required', 'string', 'max:160'], 'mode' => ['required', 'in:move,delete'], 'target' => ['nullable', 'required_if:mode,move', 'ulid']]);
        $op = $service->start($request->user(), $workspace, $v['confirmName'], $v['mode'], $v['target'] ?? null);
        $connection = config('media-studio.workspaces.operations.connection') ?? config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') !== 'sync') {
            $service->dispatch($op['id']);
        }

        return response()->json($op, 202);
    }

    public function show(Request $request, string $operation, WorkspaceRetirement $service)
    {
        return response()->json($service->card($this->find($request, $operation)))->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $operation, WorkspaceRetirement $service)
    {
        $o = $this->find($request, $operation);
        abort_unless($o->state === 'failed', 409);
        DB::table('fms_workspace_operations')->where('id', $operation)->where('state', 'failed')->update(['state' => 'queued', 'error' => null]);

        return response()->json($service->card($this->find($request, $operation)));
    }

    private function find(Request $request, string $id): object
    {
        $p = Principal::fromUser($request->user());
        $o = DB::table('fms_workspace_operations')->where('id', $id)->where('principal_key', $p->key())->first();
        abort_unless($o, 404);
        app(WorkspaceAccess::class)->authorizeTenantForWorker($p, $o->tenant_type ? new TenantIdentity($o->tenant_type, $o->tenant_id) : null);

        return $o;
    }
}
