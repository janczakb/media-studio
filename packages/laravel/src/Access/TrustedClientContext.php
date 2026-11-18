<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

/** Server-created credential context. Never hydrate this object from request input. */
final readonly class TrustedClientContext
{
    public const ATTRIBUTE = 'fms.trusted-client-context';

    public function __construct(public Principal $principal, public ?TenantIdentity $tenant) {}
}
