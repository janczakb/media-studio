<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Identity;

use Illuminate\Contracts\Auth\Authenticatable;

class AttributeMemberProfileResolver implements MemberProfileResolver
{
    public function resolve(Authenticatable $user): array
    {
        $fields = config('media-studio.member_profiles.fields', []);
        $read = static function (string $key, string $default) use ($user, $fields): ?string {
            $field = array_key_exists($key, $fields) ? $fields[$key] : $default;
            if ($field === null || ! is_string($field) || $field === '') {
                return null;
            }
            $value = data_get($user, $field);

            return is_string($value) && trim($value) !== '' ? $value : null;
        };

        return ['name' => $read('name', 'name'), 'email' => $read('email', 'email'), 'avatarUrl' => $read('avatar_url', 'avatar_url')];
    }
}
