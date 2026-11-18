<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Members;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

interface MemberDirectoryScope
{
    /** Restrict candidates to users the actor may invite into this workspace. */
    public function query(Authenticatable $actor, object $workspace): Builder;
}
