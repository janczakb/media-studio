<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class CreateWorkspace
{
    public function __construct(private WorkspaceAccess $access, private StudioPermissions $permissions) {}

    public function execute(Authenticatable $actor, string $name, ?string $requestKey = null, array $presentation = []): object
    {
        abort_unless(config('media-studio.workspace_creation') === true && config('media-studio.workspaces.enabled', true), 403);
        $this->permissions->authorize($actor, 'workspaces.create');
        $principal = Principal::fromUser($actor);
        $tenant = $this->access->tenant($principal);
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'min:3', 'max:15']])->validate();
        $emoji = $presentation['emoji'] ?? null;
        $kind = $presentation['kind'] ?? 'team';
        $fingerprint = $requestKey === null ? null : hash('sha256', $presentation === [] ? $name : json_encode([$name, $emoji, $kind], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($name, $principal, $tenant, $requestKey, $fingerprint, $emoji, $kind): object {
                if ($requestKey !== null && ($existing = $this->replay($principal, $requestKey, $fingerprint)) !== null) {
                    return $existing;
                }
                $quota = hash('sha256', $principal->key().($tenant?->key() ?? 'default'));
                DB::table('fms_workspace_limits')->insertOrIgnore(['key' => $quota]);
                DB::table('fms_workspace_limits')->where('key', $quota)->lockForUpdate()->first();
                $owned = $this->access->query($principal)->where('m.role', 'owner')->limit(max(1, (int) config('media-studio.workspaces.max_owned', 10)))->get(['w.id'])->count();
                abort_if($owned >= max(1, (int) config('media-studio.workspaces.max_owned', 10)), 422, 'The owned workspace limit has been reached.');
                $publicId = (string) Str::ulid();
                $id = DB::table('fms_workspaces')->insertGetId([
                    'public_id' => $publicId, 'name' => $name, 'emoji' => $emoji, 'kind' => $kind,
                    'tenant_type' => $tenant?->type, 'tenant_id' => $tenant?->id, 'tenant_key' => $tenant?->key(),
                    'disk' => config('media-studio.storage.disk'), 'storage_root' => config('media-studio.storage.root'),
                    'creation_request' => $requestKey, 'creation_fingerprint' => $fingerprint,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fms_memberships')->insert(['workspace_id' => $id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => 'owner']);

                return (object) ['id' => $id, 'public_id' => $publicId, 'name' => $name, 'emoji' => $emoji, 'kind' => $kind, 'role' => 'owner'];
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if ($requestKey === null || ($existing = $this->replay($principal, $requestKey, $fingerprint)) === null) {
                throw $error;
            }

            return $existing;
        }
    }

    private function replay(Principal $principal, string $requestKey, string $fingerprint): ?object
    {
        $existing = DB::table('fms_workspaces')->where('creation_request', $requestKey)->first(['public_id', 'creation_fingerprint']);
        if ($existing === null) {
            return null;
        }

        $workspace = $this->access->resolve($principal, $existing->public_id);
        abort_unless(hash_equals($existing->creation_fingerprint, $fingerprint), 409);

        return $workspace;
    }
}
