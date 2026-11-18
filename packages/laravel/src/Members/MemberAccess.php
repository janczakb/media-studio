<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Members;

use Illuminate\Http\Request;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class MemberAccess
{
    public function __construct(private WorkspaceAccess $workspaces) {}

    public function resolve(Request $request, string $workspace, string $operation = 'view', bool $lock = false): object
    {
        $resolved = $this->workspaces->resolve(Principal::fromUser($request->user()), $workspace, lock: $lock);
        abort_unless($this->allows($request, $resolved, $operation), 403);

        return $resolved;
    }

    public function allows(Request $request, object $workspace, string $operation): bool
    {
        return ! (($workspace->kind ?? 'team') === 'personal' && in_array($operation, ['manage', 'invite'], true)) && app(StudioPermissions::class)->allows($request->user(), 'members.'.$operation, $workspace);
    }

    /** Privileged workspace roles cannot be granted or removed by peers. */
    public function authorizeRole(object $workspace, string $role): void
    {
        abort_if($role === 'admin' && $workspace->role !== 'owner', 403);
    }

    public function capabilities(Request $request, object $workspace): array
    {
        $view = $this->allows($request, $workspace, 'view');

        return ['view' => $view, 'manage' => $view && $this->allows($request, $workspace, 'manage'), 'invite' => $view && $this->allows($request, $workspace, 'invite')];
    }
}
