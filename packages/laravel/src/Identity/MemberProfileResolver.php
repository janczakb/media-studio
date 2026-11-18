<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Identity;

use Illuminate\Contracts\Auth\Authenticatable;

interface MemberProfileResolver
{
    /** @return array{name: ?string, email: ?string, avatarUrl: ?string} */
    public function resolve(Authenticatable $user): array;
}
