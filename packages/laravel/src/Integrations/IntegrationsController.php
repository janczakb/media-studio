<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Slack\SlackConnections;

final class IntegrationsController
{
    public function __construct(private WorkspaceAccess $access, private SlackConnections $connections) {}

    public function show(Request $request, string $workspace): JsonResponse
    {
        $resolved = $this->access->resolve(Principal::fromUser($request->user()), $workspace);

        return response()->json($this->connections->payload($resolved, $this->canManage($request, $resolved)))->header('Cache-Control', 'no-store');
    }

    private function canManage(Request $request, object $workspace): bool
    {
        return in_array($workspace->role, ['owner', 'admin'], true)
            && app(StudioPermissions::class)->allows($request->user(), 'integrations.manage', $workspace);
    }
}
