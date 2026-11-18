<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;

final class FilamentMemberProfileResolver extends AttributeMemberProfileResolver
{
    public function resolve(Authenticatable $user): array
    {
        $profile = parent::resolve($user);
        if (! class_exists(Filament::class) || ! app()->bound('filament')) {
            throw new LogicException('Filament is required for FilamentMemberProfileResolver.');
        }
        $profile['name'] = Filament::getUserName($user) ?: $profile['name'];
        $profile['avatarUrl'] = Filament::getUserAvatarUrl($user);

        return $profile;
    }
}
