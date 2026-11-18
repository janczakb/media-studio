<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Filament;

use Filament\FilamentServiceProvider;
use Filament\Panel;
use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use UseFlex\MediaStudio\Access\PermissionDefinitions;
use UseFlex\MediaStudio\Integrations\Filament\PermissionEditors;
use UseFlex\MediaStudio\Tests\TestCase;
use Waguilar\FilamentGuardian\Contracts\PermissionKeyBuilder;
use Waguilar\FilamentGuardian\FilamentGuardianServiceProvider;
use Waguilar\FilamentGuardian\Support\PermissionLabelResolver;
use Waguilar\FilamentGuardian\Support\PermissionResolver;

final class GuardianPermissionsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class,
            LivewireServiceProvider::class, SupportServiceProvider::class,
            FilamentServiceProvider::class, FilamentGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
    }

    public function test_real_guardian_lists_all_installed_studio_permissions_for_its_guard(): void
    {
        $definitions = app(PermissionDefinitions::class)->all();
        self::assertSame($definitions, array_intersect_key(config('filament-guardian.custom_permissions'), $definitions));
        $this->artisan('media-studio:permissions')->assertSuccessful();
        $panel = Panel::make()->id('test')->authGuard('web');
        $builder = app(PermissionKeyBuilder::class);
        $resolver = new PermissionResolver($panel, 'web', $builder);
        self::assertEqualsCanonicalizing(array_keys($definitions), $resolver->getCustomPermissions()->all());
        $otherGuard = new PermissionResolver($panel, 'other', $builder);
        self::assertSame([], $otherGuard->getCustomPermissions()->all());
    }

    public function test_guardian_labels_follow_request_locale_without_overwriting_host_labels(): void
    {
        $resolver = app(PermissionLabelResolver::class);
        app()->setLocale('pl');
        self::assertStringContainsString('Pobieranie', $resolver->getLabel('studio.files.download'));
        app()->setLocale('en');
        self::assertStringContainsString('Download', $resolver->getLabel('studio.files.download'));

        config()->set('filament-guardian.custom_permissions', ['company.media.view' => 'Host-owned label']);
        config()->set('media-studio.permissions.abilities', ['view' => 'company.media.view']);
        app(PermissionEditors::class)->register();
        self::assertSame('Host-owned label', $resolver->getLabel('company.media.view'));
        app('translator')->addLines(['filament-guardian.custom.studio.write' => 'Własny zapis'], 'pl', 'filament-guardian');
        app()->setLocale('pl');
        self::assertSame('Własny zapis', $resolver->getLabel('studio.write'));
    }
}
