<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use LogicException;

final class StandaloneProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! class_exists(Fortify::class)) {
            throw new LogicException('Standalone Studio requires laravel/fortify ^1.42.');
        }
        if ($this->app->providerIsLoaded(FortifyServiceProvider::class)) {
            throw new LogicException('Standalone Studio must not replace an existing host Fortify installation. Use host authentication mode.');
        }
        config()->set('auth.guards.studio', ['driver' => 'session', 'provider' => 'fms_studio_users']);
        config()->set('auth.providers.fms_studio_users', ['driver' => 'eloquent', 'model' => StudioUser::class]);
        config()->set('auth.passwords.fms_studio_users', ['provider' => 'fms_studio_users', 'table' => 'fms_password_reset_tokens', 'expire' => 60, 'throttle' => 60]);
        config()->set('auth.defaults.guard', 'studio');
        config()->set('media-studio.principals', ['studio_user' => StudioUser::class]);
        if (! config('media-studio.tenancy.resolver')) {
            config()->set('media-studio.tenancy.resolver', PersonalTenant::class);
        }
        config()->set('fortify.guard', 'studio');
        config()->set('fortify.passwords', 'fms_studio_users');
        config()->set('fortify.prefix', trim(config('media-studio.auth.prefix', 'studio/auth'), '/'));
        config()->set('fortify.home', '/'.trim(config('media-studio.auth.home', 'studio'), '/'));
        config()->set('fortify.middleware', ['web', NormalizeEmail::class, 'throttle:fms-auth']);
        config()->set('fortify.views', true);
        config()->set('fortify.lowercase_usernames', true);
        config()->set('fortify.features', [Features::resetPasswords(), Features::emailVerification(), Features::updatePasswords(), Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]), ...(config('media-studio.auth.registration', false) ? [Features::registration()] : [])]);
        config()->set('fortify.limiters.login', 'fms-login');
        config()->set('fortify.limiters.two-factor', 'fms-two-factor');
        $this->app->register(FortifyServiceProvider::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, ResetLinkResponse::class);
        Fortify::createUsersUsing(AccountActions::class);
        Fortify::resetUserPasswordsUsing(AccountActions::class);
        Fortify::updateUserPasswordsUsing(AccountActions::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateAccount::class]);
        }
        RateLimiter::for('fms-auth', fn ($r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('fms-login', fn ($r) => Limit::perMinute(5)->by(hash('sha256', mb_strtolower($r->input('email', '')).'|'.$r->ip())));
        RateLimiter::for('fms-two-factor', fn ($r) => Limit::perMinute(5)->by((string) $r->session()->get('login.id').'|'.$r->ip()));
        foreach (['loginView' => 'login', 'registerView' => 'register', 'requestPasswordResetLinkView' => 'forgot', 'resetPasswordView' => 'reset', 'verifyEmailView' => 'verify', 'confirmPasswordView' => 'confirm', 'twoFactorChallengeView' => 'two-factor'] as $method => $screen) {
            Fortify::$method(fn ($request) => view('media-studio::auth.page', ['screen' => $screen, 'token' => $request->route('token'), 'email' => $request->query('email', '')]));
        }
        $path = trim(config('media-studio.auth.prefix', 'studio/auth'), '/');
        Route::get($path.'/assets/{asset}', function (string $asset) {
            $types = ['js' => 'text/javascript', 'css' => 'text/css', 'wasm' => 'application/wasm', 'svg' => 'image/svg+xml', 'txt' => 'text/plain'];
            $extension = pathinfo($asset, PATHINFO_EXTENSION);
            abort_unless(isset($types[$extension]), 404);
            $file = dirname(__DIR__, 3).'/react/dist/'.$asset;
            abort_unless(is_file($file), 503);

            return response()->file($file, ['Content-Type' => $types[$extension], 'Cache-Control' => 'public,max-age=3600']);
        })->where('asset', '[a-zA-Z0-9.-]+');
        Route::get(trim(config('media-studio.auth.home', 'studio'), '/'), fn () => view('media-studio::auth.studio'))->middleware(['web', 'auth:studio', 'verified']);
        Route::get($path.'/security', fn () => view('media-studio::auth.page', ['screen' => 'security', 'token' => null, 'email' => '']))->middleware(['web', 'auth:studio', 'verified', 'password.confirm']);
    }
}
