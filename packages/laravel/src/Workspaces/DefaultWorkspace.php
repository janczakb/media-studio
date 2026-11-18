<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

/** One default per tenant/application. Admission receipts prevent silent rejoining after removal. */
final class DefaultWorkspace
{
    public function scope(?TenantIdentity $tenant): string
    {
        return $tenant?->key() ?? hash('sha256', 'media-studio:application-default');
    }

    public function ensure(Authenticatable $actor): ?int
    {
        if (! config('media-studio.default_workspace.enabled', true)) {
            return null;
        }
        $permissions = app(StudioPermissions::class);
        $permissions->authorize($actor, 'view');
        $principal = Principal::fromUser($actor);
        $tenant = app(WorkspaceAccess::class)->tenant($principal);
        $scope = $this->scope($tenant);
        $existing = DB::table('fms_workspace_defaults')->where('scope_key', $scope)->value('workspace_id');
        if ($existing !== null && (! config('media-studio.default_workspace.auto_join', true)
            || DB::table('fms_default_admissions')->where('workspace_id', $existing)->where('principal_key', $principal->key())->exists())) {
            return (int) $existing;
        }

        return DB::transaction(function () use ($actor, $principal, $tenant, $scope, $permissions): int {
            DB::table('fms_workspace_defaults')->insertOrIgnore(['scope_key' => $scope, 'workspace_id' => null]);
            $default = DB::table('fms_workspace_defaults')->where('scope_key', $scope)->lockForUpdate()->first();
            $id = $default->workspace_id;
            if ($id === null) {
                $name = trim((string) config('media-studio.default_workspace.name', 'Media library'));
                abort_unless(mb_strlen($name) >= 3 && mb_strlen($name) <= 15, 422, 'Invalid default workspace name.');
                $id = DB::table('fms_workspaces')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'name' => $name,
                    'tenant_type' => $tenant?->type, 'tenant_id' => $tenant?->id, 'tenant_key' => $tenant?->key(),
                    'disk' => config('media-studio.storage.disk'), 'storage_root' => config('media-studio.storage.root'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fms_workspace_defaults')->where('scope_key', $scope)->update(['workspace_id' => $id]);
            }
            if (config('media-studio.default_workspace.auto_join', true)) {
                $workspace = DB::table('fms_workspaces')->where('id', $id)->first();
                abort_unless($workspace !== null && $workspace->tenant_key === $tenant?->key(), 404);
                $permissions->authorize($actor, 'view', $workspace);
                $new = DB::table('fms_default_admissions')->insertOrIgnore(['workspace_id' => $id, 'principal_key' => $principal->key()]);
                if ($new) {
                    $role = $permissions->allows($actor, 'members.manage', $workspace) ? 'owner'
                        : ($permissions->allows($actor, 'write', $workspace) ? 'editor' : 'viewer');
                    DB::table('fms_memberships')->insertOrIgnore([
                        'workspace_id' => $id, 'principal_type' => $principal->type, 'principal_id' => $principal->id,
                        'principal_key' => $principal->key(), 'role' => $role,
                    ]);
                }
            }

            return (int) $id;
        }, 3);
    }

    public function select(object $workspace, Principal $principal): void
    {
        abort_if(($workspace->kind ?? 'team') === 'personal', 422, 'Personal workspaces cannot become the shared default workspace.');
        $tenant = app(WorkspaceAccess::class)->tenant($principal);
        DB::transaction(function () use ($workspace, $tenant): void {
            $scope = $this->scope($tenant);
            DB::table('fms_workspace_defaults')->insertOrIgnore(['scope_key' => $scope, 'workspace_id' => null]);
            DB::table('fms_workspace_defaults')->where('scope_key', $scope)->lockForUpdate()->first();
            DB::table('fms_workspace_defaults')->where('scope_key', $scope)->update(['workspace_id' => $workspace->id]);
        }, 3);
    }
}
