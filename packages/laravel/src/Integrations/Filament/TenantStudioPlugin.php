<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LogicException;
use UseFlex\MediaStudio\Http\StudioRoutes;

final class TenantStudioPlugin implements Plugin
{
    public static function make(): self
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'flex-media-studio-tenancy';
    }

    public function register(Panel $panel): void
    {
        if (config('media-studio.tenancy.resolver') !== FilamentTenantResolver::class
            || config('media-studio.routes.base_url_resolver') !== FilamentTenantApiUrl::class) {
            throw new LogicException('Configure the Filament tenant and API URL resolvers before registering TenantStudioPlugin.');
        }
        FilamentTenantContext::register();
        $panel->tenantMiddleware([EstablishStudioTenant::class], isPersistent: true);
        $panel->authenticatedTenantRoutes(function (Panel $panel): void {
            if (! $panel->hasTenancy()) {
                throw new LogicException('The Studio tenant plugin requires Filament panel tenancy.');
            }

            StudioRoutes::register([StudioTenantRoute::class]);
        });
    }

    public function boot(Panel $panel): void {}
}
