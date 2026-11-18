<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Identity;

use Illuminate\Contracts\Auth\Authenticatable;

interface PrincipalActorResolver
{
    /** Resolve a fresh actor, including fresh permission relationships, for jobs and console operations. */
    public function resolve(Principal $principal): Authenticatable;
}
