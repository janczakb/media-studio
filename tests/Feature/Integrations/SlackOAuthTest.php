<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Feature\Integrations;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use UseFlex\MediaStudio\Tests\TestCase;

final class SlackOAuthTest extends TestCase
{
    private const CALLBACK = '/media-studio/api/v1/integrations/slack/callback';

    private const WEBHOOK = 'https://hooks.slack.com/services/T000000000/B000000000/oauth-fixture-secret';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../../../packages/laravel/database/integration-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        config(['media-studio.integrations.slack.enabled' => true,
            'media-studio.integrations.slack.oauth.client_id' => '123456.789012',
            'media-studio.integrations.slack.oauth.client_secret' => 'server-test-secret',
            'media-studio.integrations.slack.oauth.redirect_uri' => 'https://localhost'.self::CALLBACK]);
        $this->withCredentials();
        $this->app['session.store']->start();
        $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());
        Http::preventStrayRequests();
    }

    private function start(string $workspace, string $returnUrl = 'http://localhost/?fmsView=integrations'): array
    {
        $url = $this->postJson($this->url($workspace, 'integrations/slack/oauth'), ['revision' => 0, 'returnUrl' => $returnUrl])->assertOk()->json('authorizationUrl');
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        return $params + ['url' => $url];
    }

    private function response(array $overrides = []): array
    {
        return $overrides + ['ok' => true, 'scope' => 'incoming-webhook', 'access_token' => 'not-to-be-stored',
            'team' => ['id' => 'T000000', 'name' => 'Design team'],
            'incoming_webhook' => ['url' => self::WEBHOOK, 'channel' => '#design', 'channel_id' => 'C000000']];
    }

    public function test_oauth_redirect_uses_minimum_scope_and_atomic_callback_stores_only_server_received_webhook(): void
    {
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->response())]);
        $workspace = $this->workspace();
        $grant = $this->start($workspace);
        self::assertStringStartsWith('https://slack.com/oauth/v2/authorize?', $grant['url']);
        self::assertSame('incoming-webhook', $grant['scope']);
        self::assertSame('123456.789012', $grant['client_id']);
        self::assertArrayNotHasKey('client_secret', $grant);
        $stored = DB::table('fms_slack_oauth_states')->first();
        self::assertSame(hash('sha256', $grant['state']), $stored->id);
        self::assertStringNotContainsString($grant['state'], json_encode($stored));
        $callback = self::CALLBACK.'?state='.$grant['state'].'&code=slack-code';
        $this->get($callback)->assertRedirect('http://localhost/?fmsView=integrations&fmsSlack=connected')->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/oauth.v2.access'
            && $request['code'] === 'slack-code' && $request['redirect_uri'] === 'https://localhost'.self::CALLBACK
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('123456.789012:server-test-secret')));
        $connection = DB::table('fms_slack_connections')->first();
        self::assertSame(self::WEBHOOK, Crypt::decryptString($connection->webhook));
        self::assertStringNotContainsString('not-to-be-stored', json_encode($connection));
        $this->getJson($this->url($workspace, 'integrations'))->assertOk()->assertJsonPath('slack.teamName', 'Design team')->assertJsonPath('slack.channel', '#design')->assertJsonPath('slack.connected', true);
        $this->get($callback)->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_cancel_expiry_and_wrong_session_never_exchange_a_code(): void
    {
        $workspace = $this->workspace();
        $cancel = $this->start($workspace);
        $this->get(self::CALLBACK.'?state='.$cancel['state'].'&error=access_denied')->assertRedirect('http://localhost/?fmsView=integrations&fmsSlack=cancelled');
        $expired = $this->start($workspace);
        DB::table('fms_slack_oauth_states')->update(['expires_at' => now()->subMinute()]);
        $this->get(self::CALLBACK.'?state='.$expired['state'].'&code=code')->assertForbidden();
        $otherSession = $this->start($workspace);
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
        $this->get(self::CALLBACK.'?state='.$otherSession['state'].'&code=code')->assertForbidden();
        Http::assertNothingSent();
        self::assertSame(0, DB::table('fms_slack_connections')->count());
    }

    public function test_foreign_principals_and_revoked_membership_cannot_finish_authorization(): void
    {
        $workspace = $this->workspace();
        $grant = $this->start($workspace);
        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->get(self::CALLBACK.'?state='.$grant['state'].'&code=code')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        Gate::define('studio.integrations.manage', fn () => false);
        $this->get(self::CALLBACK.'?state='.$grant['state'].'&code=code')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_untrusted_return_urls_and_unconfigured_app_are_rejected_without_exposing_secrets(): void
    {
        $workspace = $this->workspace();
        foreach (['https://evil.example/', 'http://localhost@evil.example/', '//evil.example/'] as $returnUrl) {
            $this->postJson($this->url($workspace, 'integrations/slack/oauth'), ['revision' => 0, 'returnUrl' => $returnUrl])->assertUnprocessable();
        }
        config(['media-studio.integrations.slack.oauth.client_secret' => null]);
        $this->getJson($this->url($workspace, 'integrations'))->assertOk()->assertJsonPath('oauthConfigured', false);
        $this->postJson($this->url($workspace, 'integrations/slack/oauth'), ['revision' => 0, 'returnUrl' => 'http://localhost/'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_failed_exchange_and_malicious_webhook_do_not_create_a_connection(): void
    {
        $workspace = $this->workspace();
        Http::fake(['slack.com/api/oauth.v2.access' => Http::sequence()->push(['ok' => false, 'error' => 'invalid_code'])
            ->push($this->response(['incoming_webhook' => ['url' => 'https://127.0.0.1/secret', 'channel' => '#design', 'channel_id' => 'C000000']]))]);
        foreach ([1, 2] as $_) {
            $grant = $this->start($workspace);
            $this->get(self::CALLBACK.'?state='.$grant['state'].'&code=code')->assertRedirect('http://localhost/?fmsView=integrations&fmsSlack=failed');
        }
        self::assertSame(0, DB::table('fms_slack_connections')->count());
        Http::assertSentCount(2);
    }

    public function test_concurrent_changes_do_not_replace_a_connection_after_authorization(): void
    {
        $workspace = $this->workspace();
        $first = $this->start($workspace);
        $second = $this->start($workspace);
        Http::fake(['slack.com/api/oauth.v2.access' => Http::response($this->response())]);
        $this->get(self::CALLBACK.'?state='.$first['state'].'&code=code1')->assertRedirect('http://localhost/?fmsView=integrations&fmsSlack=connected');
        $this->get(self::CALLBACK.'?state='.$second['state'].'&code=code2')->assertRedirect('http://localhost/?fmsView=integrations&fmsSlack=conflict');
        Http::assertSentCount(1);
        self::assertSame(1, DB::table('fms_slack_connections')->value('revision'));
        $this->putJson($this->url($workspace, 'integrations/slack'), ['revision' => 1, 'webhook' => self::WEBHOOK, 'channel' => '#other', 'enabled' => true, 'events' => ['uploaded']])->assertUnprocessable()->assertJsonValidationErrors(['webhook', 'channel']);
    }
}
