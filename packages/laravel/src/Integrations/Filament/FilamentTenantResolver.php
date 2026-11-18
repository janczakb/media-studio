<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class FilamentTenantResolver implements TenantResolver
{
    public const CONTEXT_ATTRIBUTE = 'useflex.media-studio.filament-tenant';

    public static function identity(Model $tenant): ?TenantIdentity
    {
        $alias = array_search($tenant::class, config('media-studio.tenancy.models', []), true);
        $key = $tenant->getKey();
        if (! $tenant->exists || ! is_string($alias) || (! is_string($key) && ! is_int($key))) {
            return null;
        }

        return new TenantIdentity($alias, (string) $key);
    }

    public function current(Principal $principal): ?TenantIdentity
    {
        $context = request()->attributes->get(self::CONTEXT_ATTRIBUTE);
        if (! $context instanceof TenantIdentity || ! class_exists(Filament::class) || ! FilamentTenantContext::matches(request())) {
            return null;
        }
        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();
        if ($panel === null || ! $panel->hasTenancy() || $tenant === null || $tenant::class !== $panel->getTenantModel()) {
            return null;
        }
        $actor = $panel->auth()->user();
        if (! $actor instanceof HasTenants || ! $this->matchesPrincipal($actor, $principal)
            || self::identity($tenant)?->key() !== $context->key()) {
            return null;
        }

        return $context;
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        $actorModel = config('media-studio.principals.'.$principal->type);
        $tenantModel = config('media-studio.tenancy.models.'.$tenant->type);
        if (! is_string($actorModel) || ! is_subclass_of($actorModel, Model::class) || ! is_subclass_of($actorModel, Authenticatable::class)
            || ! is_subclass_of($actorModel, HasTenants::class) || ! is_string($tenantModel) || ! is_subclass_of($tenantModel, Model::class)) {
            return false;
        }
        $actor = new $actorModel;
        $actor = $actor->newQuery()->where($actor->getAuthIdentifierName(), $principal->id)->first();
        $model = (new $tenantModel)->newQuery()->whereKey($tenant->id)->first();

        return $actor instanceof HasTenants && $this->matchesPrincipal($actor, $principal)
            && $model !== null && $model::class === $tenantModel && (string) $model->getKey() === $tenant->id
            && $actor->canAccessTenant($model);
    }

    private function matchesPrincipal(Authenticatable $actor, Principal $principal): bool
    {
        return $actor::class === config('media-studio.principals.'.$principal->type)
            && (string) $actor->getAuthIdentifier() === $principal->id;
    }
}
