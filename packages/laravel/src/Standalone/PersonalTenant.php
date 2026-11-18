<?php

namespace UseFlex\MediaStudio\Standalone;

use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class PersonalTenant implements TenantResolver
{
    public function current(Principal $principal): ?TenantIdentity
    {
        return new TenantIdentity('studio_account', $principal->id);
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        return $tenant->type === 'studio_account' && $tenant->id === $principal->id;
    }
}
