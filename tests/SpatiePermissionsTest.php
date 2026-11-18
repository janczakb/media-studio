<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\PermissionDefinitions;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilePermissions;
use UseFlex\MediaStudio\Http\AuthorizeStudioOperation;
use UseFlex\MediaStudio\Integrations\Filament\PermissionEditors;
use UseFlex\MediaStudio\MediaStudioServiceProvider;

final class SpatiePermissionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        Schema::create('rbac_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        config()->set('media-studio.principals', ['user' => PermissionUser::class]);
        config()->set('media-studio.permissions.actor_resolver', null);
        foreach (StudioPermissions::OPERATIONS as $operation) {
            Gate::define('studio.'.$operation, static fn () => false);
            Permission::findOrCreate('studio.'.$operation, 'web');
        }
        $this->actingAs(PermissionUser::query()->create(['name' => 'Owner']));
    }

    public function test_spatie_roles_enforce_reads_writes_and_immediate_permission_revocation(): void
    {
        $user = auth()->user();
        $role = Role::findOrCreate('studio-test', 'web');
        $role->givePermissionTo(['studio.view', 'studio.write', 'studio.workspaces.create', 'studio.folders.create']);
        $user->assignRole($role);
        $workspace = $this->workspace();
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Allowed'])->assertCreated();
        $this->postJson($this->url($workspace, 'uploads'))->assertForbidden();
        $role->revokePermissionTo('studio.folders.create');
        $this->actingAs($user->fresh());
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Denied'])->assertForbidden();
        $this->getJson($this->url($workspace))->assertOk();
        $role->revokePermissionTo('studio.view');
        $this->actingAs($user->fresh());
        $this->getJson($this->url($workspace))->assertForbidden();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_links_recheck_real_spatie_role_permissions_after_cache_invalidation(): void
    {
        $user = auth()->user();
        $role = Role::findOrCreate('share-owner', 'web');
        $role->givePermissionTo(['studio.view', 'studio.write', 'studio.workspaces.create', 'studio.folders.create', 'studio.uploads.create', 'studio.files.download', 'studio.shares.manage']);
        $user->assignRole($role);
        $workspace = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'rbac-guest-upload-0001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('delegated.png'),
        ])->assertCreated()->json('id');
        $share = $this->postJson($this->url($workspace, 'shares'), ['title' => 'Delegated access', 'entryIds' => [$file], 'allowDownload' => true])->assertCreated()->json();
        $this->app['auth']->forgetGuards();
        $this->get($share['url'].'/entries/'.$file.'/download')->assertOk();
        $role->revokePermissionTo('studio.files.download');
        $this->getJson($share['url'].'/entries/'.$file.'/download')->assertForbidden();
        $this->get($share['url'].'/entries/'.$file.'/preview')->assertOk();
        $role->revokePermissionTo('studio.shares.manage');
        $this->getJson($share['url'].'/entries')->assertForbidden();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_ownership_does_not_bypass_member_settings_or_sharing_permissions(): void
    {
        $user = auth()->user();
        $user->givePermissionTo(['studio.view', 'studio.write', 'studio.workspaces.create']);
        $workspace = $this->workspace();
        $this->getJson($this->url($workspace, 'members'))->assertForbidden();
        $this->getJson($this->url($workspace, 'settings'))->assertForbidden();
        $user->givePermissionTo(['studio.members.view', 'studio.settings.view']);
        $this->actingAs($user->fresh());
        $this->getJson($this->url($workspace, 'members'))->assertOk()->assertJsonPath('items.0.canRemove', false);
        $this->getJson($this->url($workspace, 'settings'))->assertOk()->assertJsonPath('canManage', false);
        $this->patchJson($this->url($workspace, 'settings'), ['revision' => 0, 'extensions' => ['jpg'], 'chunks' => false])->assertForbidden();
    }

    public function test_custom_permission_mapping_and_empty_names_fail_closed(): void
    {
        $user = auth()->user();
        Permission::findOrCreate('company.media.browse', 'web');
        config()->set('media-studio.permissions.abilities', ['view' => 'company.media.browse']);
        self::assertFalse(app(StudioPermissions::class)->allows($user, 'view'));
        $user->givePermissionTo('company.media.browse');
        self::assertTrue(app(StudioPermissions::class)->allows($user->fresh(), 'view'));
        config()->set('media-studio.permissions.abilities', ['view' => '']);
        $this->expectException(HttpException::class);
        app(StudioPermissions::class)->allows($user, 'view');
    }

    public function test_registered_sources_are_separately_assignable_through_real_spatie_roles(): void
    {
        foreach (glob(__DIR__.'/../packages/laravel/database/application-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        $baseSource = ['type' => 'model', 'revision' => 'v1'];
        config()->set('media-studio.application_files', ['enabled' => true, 'sources' => [
            'posts' => $baseSource + ['label' => 'Posts'],
            'sales' => $baseSource,
            'spatie' => ['type' => 'spatie', 'revision' => 'v1'],
            'unregistered' => ['type' => 'disk', 'revision' => 'v1'],
        ]]);
        $this->artisan('media-studio:permissions', ['--guard' => 'web'])->assertSuccessful();
        $this->artisan('media-studio:permissions', ['--guard' => 'web'])->assertSuccessful();
        self::assertArrayNotHasKey('studio.application-files.source.unregistered', ApplicationFilePermissions::definitions());
        self::assertSame(1, Permission::where('name', 'studio.application-files.source.posts')->count());
        $user = auth()->user();
        $role = Role::findOrCreate('editor', 'web');
        $role->givePermissionTo(['studio.application-files.view', 'studio.application-files.source.posts', 'studio.application-files.source.spatie']);
        $user->assignRole($role);
        $base = '/media-studio/api/v1/application-files';
        $this->actingAs($user->fresh());
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 'posts');
        $role->givePermissionTo('studio.application-files.spatie.view');
        $this->actingAs($user->fresh());
        $this->getJson($base)->assertOk()->assertJsonCount(2, 'items');
        $role->revokePermissionTo('studio.application-files.source.posts');
        $this->actingAs($user->fresh());
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 'spatie');
        $role->revokePermissionTo('studio.application-files.view');
        $this->actingAs($user->fresh());
        $this->getJson($base)->assertForbidden();
    }

    public function test_shield_registry_preserves_host_custom_labels_and_only_registers_enabled_sources(): void
    {
        config()->set('media-studio.application_files.sources', [
            'posts' => ['type' => 'model', 'label' => 'Posts'],
            'hidden' => ['type' => 'model', 'enabled' => false],
        ]);
        config()->set('filament-shield', ['custom_permissions' => ['host.export' => 'Export', 'studio.application-files.source.posts' => 'Editorial media']]);
        config()->set('media-studio.application_files.shield', true);
        (new MediaStudioServiceProvider(app()))->boot();
        self::assertSame('Export', config('filament-shield.custom_permissions')['host.export']);
        $definitions = config('filament-shield.custom_permissions');
        self::assertSame('Editorial media', $definitions['studio.application-files.source.posts']);
        self::assertArrayHasKey('studio.application-files.spatie.view', $definitions);
        self::assertArrayNotHasKey('studio.application-files.source.hidden', $definitions);
    }

    public function test_permission_catalogue_preserves_mapped_names_labels_and_locales(): void
    {
        config()->set('media-studio.permissions.abilities', ['view' => 'company.media.read', 'write' => 'company.media.read']);
        config()->set('media-studio.permissions.labels', ['company.media.read' => 'Company library']);
        $catalogue = app(PermissionDefinitions::class);
        self::assertSame('Company library', $catalogue->all('pl')['company.media.read']);
        self::assertArrayNotHasKey('studio.view', $catalogue->all());
        self::assertArrayNotHasKey('studio.write', $catalogue->all());
        self::assertCount(count(StudioPermissions::OPERATIONS) - 1, $catalogue->all());
        self::assertStringContainsString('Pobieranie', $catalogue->all('pl')['studio.files.download']);
        self::assertStringContainsString('Download', $catalogue->all('en')['studio.files.download']);
    }

    public function test_editor_registration_has_no_database_writes_or_implicit_role_grants(): void
    {
        config()->set('filament-guardian', ['custom_permissions' => ['host.export' => 'Export']]);
        DB::enableQueryLog();
        app(PermissionEditors::class)->register();
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        self::assertSame('Export', config('filament-guardian.custom_permissions')['host.export']);
        $definitions = config('filament-guardian.custom_permissions');
        self::assertArrayHasKey('studio.members.invite', $definitions);
        self::assertArrayHasKey('studio.api.manage', $definitions);
        self::assertSame(0, DB::table('model_has_permissions')->count());
        self::assertSame(0, DB::table('model_has_roles')->count());

        config()->set('media-studio.permissions.editors.guardian', false);
        config()->set('filament-guardian.custom_permissions', ['host.export' => 'Export']);
        app(PermissionEditors::class)->register();
        self::assertSame(['host.export' => 'Export'], config('filament-guardian.custom_permissions'));
    }

    public function test_permission_installer_is_guard_scoped_idempotent_and_rejects_unknown_guard(): void
    {
        config()->set('auth.guards.media', ['driver' => 'session', 'provider' => 'users']);
        $this->artisan('media-studio:permissions', ['--guard' => 'media'])->assertSuccessful();
        $this->artisan('media-studio:permissions', ['--guard' => 'media'])->assertSuccessful();
        self::assertSame(count(StudioPermissions::OPERATIONS), Permission::where('guard_name', 'media')->count());
        $count = Permission::count();
        $this->artisan('media-studio:permissions', ['--guard' => 'missing'])->assertFailed();
        self::assertSame($count, Permission::count());
        self::assertSame(0, DB::table('role_has_permissions')->count());
    }

    public function test_installer_uses_registrar_model_and_rolls_back_a_partial_failure(): void
    {
        config()->set('media-studio.permissions.abilities', ['view' => 'company.media.first', 'write' => 'company.media.fail']);
        app(PermissionRegistrar::class)->setPermissionClass(FailingPermission::class);
        try {
            $this->artisan('media-studio:permissions')->run();
            self::fail('The model failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Permission write failed', $exception->getMessage());
        }
        self::assertSame(0, Permission::where('name', 'like', 'company.media.%')->count());
    }

    public function test_every_registered_private_studio_route_has_an_explicit_permission_mapping(): void
    {
        $routes = app('router')->getRoutes();
        foreach ($routes as $route) {
            if (! in_array(AuthorizeStudioOperation::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            [$class, $method] = explode('@', $route->getActionName());
            self::assertArrayHasKey($method, AuthorizeStudioOperation::ACTIONS[$class] ?? [], $route->uri());
        }
    }
}

final class PermissionUser extends User
{
    use HasRoles;

    protected $table = 'rbac_users';

    protected $guarded = [];

    public $timestamps = false;

    protected string $guard_name = 'web';
}

final class FailingPermission extends Permission
{
    protected static function booted(): void
    {
        self::creating(static function (Permission $permission): void {
            if ($permission->name === 'company.media.fail') {
                throw new \RuntimeException('Permission write failed');
            }
        });
    }
}
