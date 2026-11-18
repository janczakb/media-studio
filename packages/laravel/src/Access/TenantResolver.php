<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

interface TenantResolver
{
    /** Resolve the host's active, server-established tenant context. */
    public function current(Principal $principal): ?TenantIdentity;

    /** Recheck tenant access for this principal. Also called in workers, without a request or session. */
    public function allows(Principal $principal, TenantIdentity $tenant): bool;
}
