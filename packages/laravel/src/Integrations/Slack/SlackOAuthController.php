<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Slack;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class SlackOAuthController
{
    public function __construct(private WorkspaceAccess $access, private SlackOAuth $oauth) {}

    public function start(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace, write: true);
        abort_unless(in_array($resolved->role, ['owner', 'admin'], true), 403);
        app(StudioPermissions::class)->authorize($request->user(), 'integrations.manage', $resolved);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'returnUrl' => ['required', 'string', 'max:2048']]);

        return response()->json(['authorizationUrl' => $this->oauth->start($request, $resolved, $principal, (int) $data['revision'], $data['returnUrl'])])
            ->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request): RedirectResponse
    {
        return redirect()->away($this->oauth->finish($request))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
