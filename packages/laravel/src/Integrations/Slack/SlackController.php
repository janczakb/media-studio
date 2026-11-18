<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Slack;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\IntegrationsController;

final class SlackController
{
    public function __construct(private WorkspaceAccess $access, private SlackConnections $connections) {}

    public function save(Request $request, string $workspace): JsonResponse
    {
        DB::transaction(function () use ($request, $workspace): void {
            $principal = Principal::fromUser($request->user());
            $resolved = $this->access->resolve($principal, $workspace, write: true, lock: true);
            abort_unless($this->canManage($request, $resolved), 403);
            $data = $request->validate([
                'revision' => ['required', 'integer', 'min:1'],
                'webhook' => ['prohibited'], 'channel' => ['prohibited'],
                'enabled' => ['required', 'boolean'], 'events' => ['required', 'array', 'list', 'min:1', 'max:5'],
                'events.*' => ['required', 'string', 'distinct', Rule::in(SlackConnections::EVENTS)],
            ]);
            $this->connections->save($resolved, $principal, $data);
        });

        return app(IntegrationsController::class)->show($request, $workspace);
    }

    public function disconnect(Request $request, string $workspace): JsonResponse
    {
        DB::transaction(function () use ($request, $workspace): void {
            $resolved = $this->access->resolve(Principal::fromUser($request->user()), $workspace, write: true, lock: true);
            abort_unless($this->canManage($request, $resolved), 403);
            $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
            $this->connections->disconnect($resolved, $data['revision']);
        });

        return app(IntegrationsController::class)->show($request, $workspace);
    }

    private function canManage(Request $request, object $workspace): bool
    {
        return in_array($workspace->role, ['owner', 'admin'], true)
            && app(StudioPermissions::class)->allows($request->user(), 'integrations.manage', $workspace);
    }
}
