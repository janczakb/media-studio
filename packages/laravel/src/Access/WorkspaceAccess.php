<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Workspaces\DefaultWorkspace;

final class WorkspaceAccess
{
    public function query(Principal $principal): Builder
    {
        return $this->queryForTenant($principal, $this->tenant($principal));
    }

    public function tenant(Principal $principal): ?TenantIdentity
    {
        $client = request()->attributes->get(TrustedClientContext::ATTRIBUTE);
        if ($client instanceof TrustedClientContext) {
            abort_unless($client->principal->key() === $principal->key(), 404);
            $this->authorizeTenantForWorker($principal, $client->tenant);

            return $client->tenant;
        }
        $resolver = $this->tenantResolver();
        if ($resolver === null) {
            return null;
        }
        $tenant = $resolver->current($principal);
        abort_unless($tenant !== null && $resolver->allows($principal, $tenant), 404);

        return $tenant;
    }

    public function resolve(Principal $principal, string $workspaceId, bool $write = false, bool $lock = false): object
    {
        $workspace = $this->resolveQuery($this->query($principal), $workspaceId, $write, $lock);
        $actor = request()->user();
        if ($actor === null || Principal::fromUser($actor)->key() !== $principal->key()) {
            $actor = app(StudioPermissions::class)->actor($principal);
        }
        app(StudioPermissions::class)->authorize($actor, $write ? 'write' : 'view', $workspace);
        $operation = request()->attributes->get('fms.required-operation');
        if (is_array($operation) && $operation['workspace'] === $workspaceId) {
            app(StudioPermissions::class)->authorize($actor, $operation['operation'], $workspace);
        }

        return $workspace;
    }

    /** Reauthorize a trusted job context independently of any active HTTP request. */
    public function resolveForWorker(Principal $principal, string $workspaceId, ?TenantIdentity $tenant, bool $write = false, bool $lock = false, ?string $operation = null): object
    {
        $this->authorizeTenantForWorker($principal, $tenant);

        $workspace = $this->resolveQuery($this->queryForTenant($principal, $tenant), $workspaceId, $write, $lock);
        $actor = app(StudioPermissions::class)->actor($principal);
        app(StudioPermissions::class)->authorize($actor, $write ? 'write' : 'view', $workspace);
        if ($operation !== null) {
            app(StudioPermissions::class)->authorize($actor, $operation, $workspace);
        }

        return $workspace;
    }

    public function authorizeTenantForWorker(Principal $principal, ?TenantIdentity $tenant): void
    {
        $resolver = $this->tenantResolver();
        abort_unless($resolver === null ? $tenant === null : $tenant !== null && $resolver->allows($principal, $tenant), 404);
    }

    private function queryForTenant(Principal $principal, ?TenantIdentity $tenant): Builder
    {
        return DB::table('fms_workspaces as w')
            ->join('fms_memberships as m', 'm.workspace_id', '=', 'w.id')
            ->when($tenant === null, fn (Builder $query) => $query->whereNull('w.tenant_key'),
                fn (Builder $query) => $query->where('w.tenant_key', $tenant->key()))
            ->when(! config('media-studio.workspaces.enabled', true), fn (Builder $q) => $q->whereIn('w.id', DB::table('fms_workspace_defaults')->select('workspace_id')->where('scope_key', app(DefaultWorkspace::class)->scope($tenant))))
            ->where('m.principal_key', $principal->key())
            ->whereIn('m.role', ['owner', 'admin', 'editor', 'viewer'])
            ->select('w.*', 'm.role')
            ->selectRaw('EXISTS (SELECT 1 FROM fms_workspace_defaults d WHERE d.workspace_id = w.id) AS is_default');
    }

    private function resolveQuery(Builder $query, string $workspaceId, bool $write, bool $lock): object
    {
        $query->where('w.public_id', $workspaceId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $workspace = $query->first();
        abort_if($workspace === null, 404);
        abort_if($write && ($workspace->tree_operation_id ?? null) !== null, 423, 'A folder operation is preparing. Writes are temporarily locked.');
        abort_if($write && ($workspace->encryption_operation ?? null) !== null, 423, 'Workspace files are being encrypted. Writes are temporarily locked.');
        abort_if($write && ($workspace->retirement_id ?? null) !== null, 423, 'This workspace is being removed. Writes are temporarily locked.');
        abort_if($write && ! in_array($workspace->role, ['owner', 'admin', 'editor'], true), 403);

        return $workspace;
    }

    private function tenantResolver(): ?TenantResolver
    {
        $type = config('media-studio.tenancy.resolver');
        if ($type === null) {
            return null;
        }
        if (! is_string($type) || $type === '') {
            throw new LogicException('Configure a tenant resolver container key.');
        }
        $resolver = app($type);
        if (! $resolver instanceof TenantResolver) {
            throw new LogicException('The tenant resolver must implement TenantResolver.');
        }

        return $resolver;
    }
}
