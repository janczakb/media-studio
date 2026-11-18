<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class DefaultWorkspaceTest extends TestCase
{
    public function test_default_is_created_once_without_create_permission_and_cannot_be_deleted(): void
    {
        config()->set('media-studio.default_workspace.enabled', true);
        config()->set('media-studio.workspace_creation', false);
        Gate::define('studio.workspaces.create', fn () => false);
        $first = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.isDefault', true)->assertJsonPath('canCreate', false)->json('items.0');
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonPath('items.0.id', $first['id']);
        $this->getJson(rtrim($this->url($first['id'], ''), '/'))->assertOk()->assertJsonPath('isDefault', true);
        $this->postJson($this->url($first['id'], 'folders'), ['name' => 'Ready'])->assertCreated();
        self::assertSame(1, DB::table('fms_workspaces')->count());
        $this->expectException(QueryException::class);
        DB::table('fms_workspaces')->delete();
    }

    public function test_removing_default_membership_is_not_undone_by_opening_library(): void
    {
        config()->set('media-studio.default_workspace.enabled', true);
        $id = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->json('items.0.id');
        DB::table('fms_memberships')->delete();
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url($id))->assertNotFound();
    }

    public function test_defaults_are_tenant_scoped_and_viewer_never_receives_write_access(): void
    {
        config()->set('media-studio.default_workspace.enabled', true);
        config()->set('media-studio.tenancy.resolver', DefaultTenantResolver::class);
        $a = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->json('items.0.id');
        config()->set('test.default.tenant', 'other');
        Gate::define('studio.write', fn () => false);
        Gate::define('studio.members.manage', fn () => false);
        $b = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.role', 'viewer')->json('items.0.id');
        self::assertNotSame($a, $b);
        $this->getJson($this->url($a))->assertNotFound();
        $this->postJson($this->url($b, 'folders'), ['name' => 'Denied'])->assertForbidden();
    }

    public function test_default_can_be_renamed_replaced_and_auto_creation_disabled(): void
    {
        config()->set('media-studio.default_workspace.enabled', true);
        $default = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->json('items.0.id');
        $this->patchJson(rtrim($this->url($default, ''), '/'), ['name' => 'Company media'])->assertOk()->assertJsonPath('name', 'Company media');
        $other = $this->workspace('New default');
        $this->postJson($this->url($other, 'make-default'))->assertOk()->assertJsonPath('isDefault', true);
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonPath('items.0.id', $other)->assertJsonPath('items.0.isDefault', true);
        config()->set('media-studio.default_workspace.enabled', false);
        $this->actingAs(new GenericUser(['id' => 'unrelated-user']));
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonCount(0, 'items');
        self::assertSame(2, DB::table('fms_workspaces')->count());
    }
}

final class DefaultTenantResolver implements TenantResolver
{
    public function current(Principal $principal): ?TenantIdentity
    {
        return new TenantIdentity('organization', config('test.default.tenant', 'first'));
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        return $tenant->id === config('test.default.tenant', 'first');
    }
}
