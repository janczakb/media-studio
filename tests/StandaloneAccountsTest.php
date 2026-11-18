<?php

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionServiceProvider;
use UseFlex\MediaStudio\Mail\StudioMail;
use UseFlex\MediaStudio\Standalone\AccountActions;
use UseFlex\MediaStudio\Standalone\StandaloneProvider;
use UseFlex\MediaStudio\Standalone\StudioUser;

final class StandaloneAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(PermissionServiceProvider::class);
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        (require __DIR__.'/../packages/laravel/database/auth-migrations/2026_11_11_000001_standalone_accounts.php')->up();
        config(['media-studio.auth.enabled' => true, 'media-studio.auth.registration' => true, 'media-studio.default_workspace.enabled' => true, 'mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        $this->app->register(StandaloneProvider::class);
        Auth::forgetGuards();
        Notification::fake();
    }

    public function test_standalone_serves_lazy_preview_assets_but_not_build_metadata_or_paths(): void
    {
        $this->get('/studio/auth/assets/office-preview.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->get('/studio/auth/assets/pdfium.wasm')->assertOk()->assertHeader('Content-Type', 'application/wasm');
        $this->get('/studio/auth/assets/studio.css')->assertOk();
        $this->get('/studio/auth/assets/build-meta.json')->assertNotFound();
        $this->get('/studio/auth/assets/does-not-exist.js')->assertStatus(503);
        $this->get('/studio/auth/assets/../composer.json')->assertNotFound();
    }

    public function test_registration_login_and_verified_access_use_an_independent_account_model(): void
    {
        $input = ['name' => 'Independent owner', 'email' => 'OWNER@example.test', 'password' => 'Correct-Password123', 'password_confirmation' => 'Correct-Password123'];
        $this->postJson('/studio/auth/register', $input)->assertCreated();
        $user = StudioUser::firstOrFail();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertTrue(Hash::check($input['password'], $user->password));
        $this->assertFalse($user->hasVerifiedEmail());
        $this->postJson('/studio/auth/logout')->assertNoContent();
        $this->postJson('/studio/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/studio/auth/login', ['email' => $user->email, 'password' => $input['password']])->assertOk();
        $this->get('/studio')->assertRedirect('/studio/auth/email/verify');
        $user->markEmailAsVerified();
        $this->actingAs($user->fresh(), 'studio');
        $this->get('/studio')->assertOk();
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonPath('viewer.email', $user->email);
    }

    public function test_password_reset_changes_password_and_rotates_remember_token(): void
    {
        $user = app(AccountActions::class)->provision(['name' => 'Owner', 'email' => 'reset@example.test', 'password' => 'Initial-Password123', 'password_confirmation' => 'Initial-Password123']);
        $token = Password::broker('fms_studio_users')->createToken($user);
        $this->postJson('/studio/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Changed-Password987', 'password_confirmation' => 'Changed-Password987'])->assertOk();
        $this->assertTrue(Hash::check('Changed-Password987', $user->fresh()->password));
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->postJson('/studio/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Other-Password987', 'password_confirmation' => 'Other-Password987'])->assertUnprocessable();
    }

    public function test_confirmed_two_factor_setup_and_login_challenge(): void
    {
        $user = app(AccountActions::class)->provision(['name' => 'Owner', 'email' => 'twofactor@example.test', 'password' => 'Initial-Password123', 'password_confirmation' => 'Initial-Password123']);
        $user->markEmailAsVerified();
        $this->actingAs($user, 'studio')->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/studio/auth/user/two-factor-authentication')->assertOk();
        $user = $user->fresh();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/studio/auth/user/confirmed-two-factor-authentication', ['code' => $code])->assertOk();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->getJson('/studio/auth/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8);
        $this->postJson('/studio/auth/logout')->assertNoContent();
        $this->postJson('/studio/auth/login', ['email' => $user->email, 'password' => 'Initial-Password123'])->assertOk()->assertJsonPath('two_factor', true);
    }

    public function test_accounts_are_isolated_and_login_attempts_are_rate_limited(): void
    {
        $actions = app(AccountActions::class);
        $a = $actions->provision(['name' => 'First', 'email' => 'first@example.test', 'password' => 'Correct-Password123', 'password_confirmation' => 'Correct-Password123']);
        $a->markEmailAsVerified();
        $this->actingAs($a->fresh(), 'studio');
        $first = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->json('items.0.id');
        $b = $actions->provision(['name' => 'Second', 'email' => 'second@example.test', 'password' => 'Correct-Password123', 'password_confirmation' => 'Correct-Password123']);
        $b->markEmailAsVerified();
        $this->actingAs($b->fresh(), 'studio');
        $this->getJson('/media-studio/api/v1/workspaces/'.$first)->assertNotFound();
        $this->postJson('/studio/auth/logout')->assertNoContent();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/studio/auth/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/studio/auth/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_studio_mail_uses_host_or_an_isolated_tls_transport(): void
    {
        $this->assertNull(StudioMail::name());
        $default = config('mail.default');
        config(['media-studio.mail.mode' => 'smtp', 'media-studio.mail.smtp' => ['host' => 'smtp.example.test', 'scheme' => 'smtps', 'port' => 465, 'username' => 'test', 'password' => 'fixture-only']]);
        $this->assertSame('fms_studio', StudioMail::name());
        $this->assertSame($default, config('mail.default'));
        $this->assertTrue(config('mail.mailers.fms_studio.require_tls'));
        config(['media-studio.mail.mode' => 'mailer', 'media-studio.mail.mailer' => 'array']);
        $this->assertSame('array', StudioMail::name());
    }
}
