<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Http\ApiBaseUrlResolver;

final class FilamentTenantApiUrl implements ApiBaseUrlResolver
{
    public function resolve(): string
    {
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();
        abort_unless($panel !== null && $panel->hasTenancy() && $tenant !== null, 404);
        $name = $panel->generateRouteName('media-studio.workspaces');
        abort_unless(Route::has($name), 404);
        $key = $panel->getTenantSlugAttribute() === null
            ? $tenant->getRouteKey()
            : $tenant->getAttribute($panel->getTenantSlugAttribute());

        return Str::beforeLast(route($name, ['tenant' => $key]), '/workspaces');
    }
}
