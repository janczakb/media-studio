<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use UseFlex\MediaStudio\Access\StudioPermissions;

final class AccountActions implements CreatesNewUsers, ResetsUserPasswords, UpdatesUserPasswords
{
    public static function passwordRules(): array
    {
        return ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers(), 'max:128', 'confirmed'];
    }

    public function create(array $input): StudioUser
    {
        abort_unless(config('media-studio.auth.registration', false), 403);

        return $this->provision($input);
    }

    public function provision(array $input): StudioUser
    {
        $input['email'] = mb_strtolower(trim($input['email'] ?? ''));
        Validator::make($input, ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:191', Rule::unique('fms_users')], 'password' => self::passwordRules()])->validate();

        return DB::transaction(function () use ($input) {
            $user = StudioUser::create(['name' => trim($input['name']), 'email' => $input['email'], 'password' => Hash::make($input['password'])]);
            $class = config('permission.models.permission');
            foreach (StudioPermissions::OPERATIONS as $operation) {
                $user->givePermissionTo($class::findOrCreate(StudioPermissions::ability($operation), 'studio'));
            }

            return $user;
        });
    }

    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => self::passwordRules()])->validate();
        $user->forceFill(['password' => Hash::make($input['password']), 'remember_token' => Str::random(60)])->save();
    }

    public function update($user, array $input): void
    {
        Validator::make($input, ['current_password' => ['required', 'current_password:studio'], 'password' => self::passwordRules()])->validate();
        $this->reset($user, $input);
    }
}
