<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

final class StudioUser extends User implements MustVerifyEmail
{
    use HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $table = 'fms_users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $guard_name = 'studio';

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime'];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new StudioResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new StudioVerifyEmail);
    }
}
