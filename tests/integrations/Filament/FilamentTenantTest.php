<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Integrations\Filament\FilamentMemberProfileResolver;
use UseFlex\MediaStudio\Integrations\Filament\FilamentTenantApiUrl;
use UseFlex\MediaStudio\Integrations\Filament\FilamentTenantResolver;
use UseFlex\MediaStudio\Integrations\Filament\TenantStudioPlugin;
use UseFlex\MediaStudio\Tests\TestCase;

final class FilamentTenantTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LivewireServiceProvider::class,
            BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class, ActionsServiceProvider::class,
            SchemasServiceProvider::class, FormsServiceProvider::class, FilamentServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tenant_test_users', fn (Blueprint $t) => $t->string('id')->primary());
        Schema::create('tenant_test_companies', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('slug')->unique();
        });
        Schema::create('tenant_test_access', function (Blueprint $t): void {
            $t->string('user_id');
            $t->string('tenant_id');
        });
        DB::table('tenant_test_users')->insert(['id' => 'member-uuid']);
        DB::table('tenant_test_companies')->insert([['id' => 'Company-A', 'slug' => 'first'], ['id' => 'Company-B', 'slug' => 'second']]);
        DB::table('tenant_test_access')->insert([['user_id' => 'member-uuid', 'tenant_id' => 'Company-A'], ['user_id' => 'member-uuid', 'tenant_id' => 'Company-B']]);
        config()->set('media-studio.principals', ['user' => TenantTestUser::class]);
        config()->set('media-studio.tenancy', ['resolver' => FilamentTenantResolver::class, 'models' => ['company' => TenantTestCompany::class]]);
        config()->set('media-studio.routes.base_url_resolver', FilamentTenantApiUrl::class);
        config()->set('auth.guards.studio-panel', ['driver' => 'session', 'provider' => 'users']);
        $this->actingAs(TenantTestUser::findOrFail('member-uuid'), 'studio-panel');
        $this->actingAs(new GenericUser(['id' => 'member-uuid']), 'web');
        $panel = Panel::make()->id('studio-test')->default()->path('admin')->tenant(TenantTestCompany::class, slugAttribute: 'slug')
            ->authGuard('studio-panel')->middleware(['web'])->authMiddleware([Authenticate::class])->plugin(TenantStudioPlugin::make());
        app(PanelRegistry::class)->register($panel);
        Route::name('filament.studio-test.')->prefix('admin')->middleware($panel->getMiddleware())
            ->group(function () use ($panel): void {
                Route::middleware($panel->getAuthMiddleware())->prefix('{tenant:slug}')->middleware($panel->getTenantMiddleware())
                    ->group(function () use ($panel): void {
                        foreach ($panel->getAuthenticatedTenantRoutes() as $routes) {
                            $routes($panel);
                        }
                        Route::get('fixture', fn () => response()->json(['tenant' => Filament::getTenant()?->getKey()]));
                    });
            });
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_member_profile_adapter_uses_filament_name_and_avatar(): void
    {
        $user = new MemberProfileFixture;
        $user->email = 'profile@example.test';
        $profile = app(FilamentMemberProfileResolver::class)->resolve($user);
        self::assertSame('Filament Display Name', $profile['name']);
        self::assertSame('profile@example.test', $profile['email']);
        self::assertSame('https://example.test/avatar.png', $profile['avatarUrl']);
    }

    public function test_native_tenant_routes_scope_creation_bytes_references_and_generated_urls(): void
    {
        $this->assertArrayHasKey('studio-test', Filament::getPanels());
        $this->assertSame('studio-test', Filament::getPanel('studio-test')->getId());
        $first = '/admin/first/media-studio/api/v1/workspaces';
        $second = '/admin/second/media-studio/api/v1/workspaces';
        $workspace = $this->postJson($first, ['name' => 'Company one'])->assertCreated()->json('id');
        $this->assertDatabaseHas('fms_workspaces', ['public_id' => $workspace, 'tenant_id' => 'Company-A']);
        $folder = $this->postJson($first.'/'.$workspace.'/folders', ['name' => 'Owned'])->assertCreated()->json('id');
        $file = $this->withHeader('Idempotency-Key', 'filament-tenant-image-001')
            ->postJson($first.'/'.$workspace.'/uploads', ['file' => UploadedFile::fake()->image('private.png', 24, 24), 'parent' => $folder])
            ->assertCreated()->json();
        $versionPath = $first.'/'.$workspace.'/entries/'.$file['id'].'/versions/'.$file['versionId'].'/content';
        $this->get($versionPath)->assertOk()->assertHeader('Content-Type', 'image/png');
        $reference = [['assetId' => $file['id'], 'versionId' => $file['versionId']]];
        $resolved = $this->postJson($first.'/'.$workspace.'/references/resolve', ['references' => $reference])->assertOk()->json('items.0.url');
        $this->assertSame(url($versionPath), $resolved);
        $details = $this->getJson($first.'/'.$workspace.'/entries/'.$file['id'])->assertOk();
        $this->assertStringStartsWith(url($first.'/'.$workspace.'/entries/'), $details->json('thumbnailUrl'));
        $this->getJson($second.'/'.$workspace.'/entries/'.$file['id'].'/versions/'.$file['versionId'].'/content')->assertNotFound();
        $this->postJson($second.'/'.$workspace.'/references/resolve', ['references' => $reference])->assertNotFound();
        $this->getJson($first.'/'.$workspace.'/entries')->assertOk()->assertJsonPath('items.0.id', $folder);
        $this->getJson($second.'/'.$workspace.'/entries')->assertNotFound();
        $this->getJson('/media-studio/api/v1/workspaces/'.$workspace.'/entries')->assertForbidden();
        $this->getJson($first)->assertOk()->assertJsonCount(1, 'items');
        $this->assertSame(url($first), ApiUrl::base().'/workspaces');

        $identity = new TenantIdentity('company', 'Company-A');
        $principal = new Principal('user', 'member-uuid');
        $this->assertSame($workspace, app(WorkspaceAccess::class)->resolveForWorker($principal, $workspace, $identity)->public_id);
        DB::table('tenant_test_access')->where('tenant_id', 'Company-A')->delete();
        $this->getJson($first.'/'.$workspace.'/entries')->assertNotFound();
        try {
            app(WorkspaceAccess::class)->resolveForWorker($principal, $workspace, $identity);
            $this->fail('The worker retained revoked access.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_mixed_livewire_snapshot_origin_cannot_reuse_a_previous_tenant_context(): void
    {
        $this->getJson('/admin/first/fixture')->assertOk();
        $request = app('request');
        $this->assertNotNull($request->attributes->get(FilamentTenantResolver::CONTEXT_ATTRIBUTE));
        $requests = \Mockery::mock(HandleRequests::class);
        $requests->shouldReceive('isLivewireRoute')->andReturnTrue();
        app()->instance(HandleRequests::class, $requests);
        \Livewire\trigger('snapshot-verified', ['memo' => ['path' => 'admin/first/fixture', 'method' => 'GET']]);
        $resolver = app(FilamentTenantResolver::class);
        $principal = new Principal('user', 'member-uuid');
        $this->assertSame('Company-A', $resolver->current($principal)?->id);

        \Livewire\trigger('snapshot-verified', ['memo' => ['path' => 'public/form', 'method' => 'GET']]);
        $this->assertNull($resolver->current($principal));
        \Livewire\trigger('snapshot-verified', ['memo' => []]);
        $this->assertNull($resolver->current($principal));
    }
}

final class TenantTestCompany extends Model
{
    protected $table = 'tenant_test_companies';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}

final class TenantTestUser extends Authenticatable implements FilamentUser, HasTenants
{
    protected $table = 'tenant_test_users';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'studio-test';
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return DB::table('tenant_test_access')->where('user_id', $this->getKey())->where('tenant_id', $tenant->getKey())->exists();
    }

    public function getTenants(Panel $panel): array
    {
        return TenantTestCompany::whereIn('id', DB::table('tenant_test_access')->where('user_id', $this->getKey())->select('tenant_id'))->get()->all();
    }
}

final class MemberProfileFixture extends Authenticatable implements HasAvatar, HasName
{
    public function getFilamentAvatarUrl(): ?string
    {
        return 'https://example.test/avatar.png';
    }

    public function getFilamentName(): string
    {
        return 'Filament Display Name';
    }
}
