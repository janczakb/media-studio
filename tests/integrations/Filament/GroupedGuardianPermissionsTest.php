<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Panel;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\Exceptions\Halt;
use Filament\Support\SupportServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use UseFlex\MediaStudio\Integrations\Filament\Guardian\GroupedRolePermissions;
use UseFlex\MediaStudio\Tests\TestCase;
use Waguilar\FilamentGuardian\Concerns\SyncsPermissions;
use Waguilar\FilamentGuardian\FilamentGuardianPlugin;
use Waguilar\FilamentGuardian\FilamentGuardianServiceProvider;
use Waguilar\FilamentGuardian\Schemas\PermissionsSchemaBuilder;

final class GroupedGuardianPermissionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class,
            LivewireServiceProvider::class, BladeIconsServiceProvider::class, BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class, ActionsServiceProvider::class, SchemasServiceProvider::class,
            FormsServiceProvider::class, NotificationsServiceProvider::class, FilamentServiceProvider::class,
            FilamentGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        DatabaseSchema::create('guardian_test_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        config()->set('filament-guardian.super_admin.enabled', false);
        config()->set('filament-guardian.prevent_privilege_escalation', true);
        Filament::setCurrentPanel(Panel::make()->id('guardian-group-test')->authGuard('web')->plugin(FilamentGuardianPlugin::make()));
        $this->artisan('media-studio:permissions')->assertSuccessful();
        Permission::findOrCreate('host.export', 'web');
        $user = GuardianGroupUser::query()->create(['name' => 'Role editor']);
        $user->givePermissionTo(Permission::where('guard_name', 'web')->get());
        $this->actingAs($user);
    }

    public function test_roundtrip_preserves_other_permissions_and_saves_group_changes_through_guardian(): void
    {
        $role = Role::findOrCreate('mixed', 'web');
        $role->givePermissionTo(['host.export', 'studio.view', 'studio.folders.create', 'studio.members.view']);
        $form = Livewire::test(GroupedGuardianForm::class, ['roleId' => $role->id])
            ->assertSee('Media Studio')->assertSee('Files and library')->assertSee('Workspaces and people')
            ->assertSet('data.custom_permissions', ['host.export'])
            ->assertSet('data.media_studio_files_permissions', ['studio.view'])
            ->assertSet('data.media_studio_folders_permissions', ['studio.folders.create']);
        $form->call('save')->assertHasNoErrors();
        self::assertEqualsCanonicalizing(['host.export', 'studio.view', 'studio.folders.create', 'studio.members.view'], $role->fresh()->permissions->pluck('name')->all());
        $form->set('data.media_studio_files_permissions', ['studio.view', 'studio.files.download'])
            ->set('data.media_studio_folders_permissions', [])->call('save')->assertHasNoErrors();
        self::assertEqualsCanonicalizing(['host.export', 'studio.view', 'studio.files.download', 'studio.members.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_global_select_all_and_clear_cover_every_group_and_other_permissions(): void
    {
        $role = Role::findOrCreate('all', 'web');
        $form = Livewire::test(GroupedGuardianForm::class, ['roleId' => $role->id]);
        $form->set('data.select_all', true)->call('save')->assertHasNoErrors();
        self::assertSame(42, $role->fresh()->permissions()->count());
        $form->set('data.select_all', false)->call('save')->assertHasNoErrors();
        self::assertSame(0, $role->fresh()->permissions()->count());
    }

    public function test_tampered_group_values_fail_validation_without_altering_existing_grants(): void
    {
        $role = Role::findOrCreate('protected', 'web');
        $role->givePermissionTo('host.export');
        Livewire::test(GroupedGuardianForm::class, ['roleId' => $role->id])
            ->set('data.media_studio_files_permissions', ['host.export'])
            ->call('save')->assertHasErrors('data.media_studio_files_permissions.0');
        self::assertSame(['host.export'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_guardian_still_blocks_granting_permissions_the_actor_does_not_hold(): void
    {
        $role = Role::findOrCreate('restricted', 'web');
        $role->givePermissionTo('host.export');
        $user = auth()->user();
        $user->syncPermissions(['host.export']);
        $this->actingAs($user->fresh());
        Livewire::test(GroupedGuardianForm::class, ['roleId' => $role->id])
            ->set('data.media_studio_files_permissions', ['studio.view'])->call('save');
        self::assertSame(['host.export'], $role->fresh()->permissions->pluck('name')->all());
    }
}

final class GroupedGuardianForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use SyncsPermissions;

    #[Locked]
    public Role $record;

    public ?array $data = [];

    public function mount(int $roleId): void
    {
        $this->record = Role::findOrFail($roleId);
        $this->form->fill(['custom_permissions' => $this->record->permissions->pluck('name')->all()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->record($this->record)->statePath('data')->components(
            GroupedRolePermissions::apply(PermissionsSchemaBuilder::make()->mode(PermissionsSchemaBuilder::MODE_ROLE)->build())
        );
    }

    public function save(): void
    {
        try {
            $this->capturePermissionsFromFormData($this->form->getState());
            $this->syncCapturedPermissions();
        } catch (Halt) {
            return;
        }
    }

    protected function halt(): never
    {
        throw new Halt;
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}

final class GuardianGroupUser extends User
{
    use HasRoles;

    protected $table = 'guardian_test_users';

    protected $guarded = [];

    public $timestamps = false;

    protected string $guard_name = 'web';
}
