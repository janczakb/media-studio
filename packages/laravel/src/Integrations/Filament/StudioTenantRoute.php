<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

final class StudioTenantRoute
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(Filament::getTenant() !== null && $request->attributes->has(FilamentTenantResolver::CONTEXT_ATTRIBUTE), 404);
        $request->setUserResolver(static fn () => Filament::auth()->user());
        // Filament has already resolved this route parameter and checked tenant membership.
        $request->route()->forgetParameter('tenant');

        return $next($request);
    }
}
