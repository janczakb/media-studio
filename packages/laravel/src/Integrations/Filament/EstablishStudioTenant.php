<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

final class EstablishStudioTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->remove(FilamentTenantResolver::CONTEXT_ATTRIBUTE);
        app('request')->attributes->remove(FilamentTenantResolver::CONTEXT_ATTRIBUTE);
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();
        if ($panel?->hasTenancy() && $tenant !== null && $request->route()?->hasParameter('tenant')) {
            $identity = FilamentTenantResolver::identity($tenant);
            abort_if($identity === null, 404);
            FilamentTenantContext::establish($request);
            // Livewire replays persistent middleware against a synthetic request.
            $request->attributes->set(FilamentTenantResolver::CONTEXT_ATTRIBUTE, $identity);
            app('request')->attributes->set(FilamentTenantResolver::CONTEXT_ATTRIBUTE, $identity);
        }

        return $next($request);
    }
}
