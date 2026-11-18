<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Feature\Integrations;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Slack\SlackConnections;
use UseFlex\MediaStudio\Tests\TestCase;

final class SlackTest extends TestCase
{
    private const WEBHOOK = 'https://hooks.slack.com/services/T000000000/B000000000/fixture-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../../../packages/laravel/database/integration-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        config(['media-studio.integrations.slack.enabled' => true]);
        Http::preventStrayRequests();
    }

    private function connect(string $workspace, array $overrides = []): array
    {
        $resolved = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        app(SlackConnections::class)->connect($resolved,
            new Principal('user', 'member-uuid-001'), 0,
            ['webhook' => self::WEBHOOK, 'channel' => '#design', 'channelId' => 'C000000', 'teamId' => 'T000000', 'teamName' => 'Design team']);
        DB::table('fms_slack_connections')->where('workspace_id', $resolved->id)->update(['events' => json_encode(['uploaded'])]);

        return $this->getJson($this->url($workspace, 'integrations'))->assertOk()->json();
    }

    private function activity(string $workspace, string $name, string $event = 'uploaded'): int
    {
        $id = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');

        return DB::table('fms_activity')->insertGetId([
            'public_id' => (string) Str::ulid(), 'workspace_id' => $id, 'entry_public_id' => (string) Str::ulid(),
            'event_key' => hash('sha256', (string) Str::uuid()), 'event' => $event,
            'actor_type' => 'user', 'actor_id' => 'member-uuid-001', 'actor_key' => str_repeat('a', 64),
            'name' => $name, 'kind' => 'file', 'media_type' => 'image', 'revision' => 1, 'created_at' => now(),
        ]);
    }

    public function test_secret_is_encrypted_and_never_returned_and_updates_are_revision_protected(): void
    {
        $workspace = $this->workspace();
        $payload = $this->connect($workspace);
        self::assertTrue($payload['slack']['connected']);
        self::assertStringNotContainsString(self::WEBHOOK, json_encode($payload));
        $stored = DB::table('fms_slack_connections')->value('webhook');
        self::assertNotSame(self::WEBHOOK, $stored);
        self::assertSame(self::WEBHOOK, Crypt::decryptString($stored));
        $this->getJson($this->url($workspace, 'integrations'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->putJson($this->url($workspace, 'integrations/slack'), ['revision' => 2, 'events' => ['uploaded'], 'enabled' => true])->assertConflict();
        $this->putJson($this->url($workspace, 'integrations/slack'), ['revision' => 1, 'events' => ['uploaded'], 'enabled' => true])->assertOk()->assertJsonPath('slack.revision', 2);
        self::assertSame($stored, DB::table('fms_slack_connections')->value('webhook'));
        Http::assertNothingSent();
    }

    public function test_disabled_feature_permissions_membership_and_destination_validation_are_enforced(): void
    {
        $workspace = $this->workspace();
        config(['media-studio.integrations.slack.enabled' => false]);
        $this->getJson($this->url($workspace, 'integrations'))->assertOk()->assertJsonPath('available', false);
        $this->postJson($this->url($workspace, 'integrations/slack/oauth'), ['revision' => 0, 'returnUrl' => 'http://localhost/'])->assertConflict();
        config(['media-studio.integrations.slack.enabled' => true]);
        foreach (['http://hooks.slack.com/services/T000000000/B000000000/fixture-secret', 'https://example.test/webhook', self::WEBHOOK.'?redirect=https://127.0.0.1'] as $url) {
            $this->putJson($this->url($workspace, 'integrations/slack'), ['revision' => 0, 'channel' => '#team', 'webhook' => $url, 'enabled' => true, 'events' => ['uploaded']])->assertUnprocessable();
        }
        Gate::define('studio.integrations.view', fn () => false);
        $this->getJson($this->url($workspace, 'integrations'))->assertForbidden();
        Gate::define('studio.integrations.manage', fn () => false);
        $this->putJson($this->url($workspace, 'integrations/slack'), [])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'other-member']));
        $this->getJson($this->url($workspace, 'integrations'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_only_new_selected_activity_is_batched_and_cursor_prevents_replay(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok')]);
        $workspace = $this->workspace('Design');
        $other = $this->workspace('Other workspace');
        $this->activity($workspace, 'historical.jpg');
        $this->connect($workspace);
        $this->activity($workspace, 'new.jpg');
        $this->activity($workspace, 'private comment', 'mentioned');
        $this->activity($other, 'other-workspace.jpg');
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'new.jpg')
            && ! str_contains($request['text'], 'historical.jpg') && ! str_contains($request['text'], 'private comment')
            && ! str_contains($request['text'], 'other-workspace.jpg') && $request['mrkdwn'] === false);
        $this->travel(61)->seconds();
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertSentCount(1);
        self::assertNotNull(DB::table('fms_slack_connections')->value('last_delivered_at'));
    }

    public function test_retry_after_preserves_cursor_and_disconnect_prevents_future_delivery(): void
    {
        Http::fake([self::WEBHOOK => Http::sequence()->push('rate limited', 429, ['Retry-After' => '180'])->push('ok')]);
        $workspace = $this->workspace();
        $this->connect($workspace);
        $cursor = DB::table('fms_slack_connections')->value('activity_cursor');
        $this->activity($workspace, 'retry.jpg');
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        self::assertSame($cursor, DB::table('fms_slack_connections')->value('activity_cursor'));
        self::assertSame('temporarily_unavailable', DB::table('fms_slack_connections')->value('last_error'));
        $this->travel(181)->seconds();
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertSentCount(2);
        $this->deleteJson($this->url($workspace, 'integrations/slack'), ['revision' => 1])->assertOk()->assertJsonPath('slack.connected', false);
        self::assertNull(DB::table('fms_slack_connections')->value('webhook'));
        $this->activity($workspace, 'after-disconnect.jpg');
        $this->travel(61)->seconds();
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_worker_rechecks_permissions_and_admin_membership_before_sending(): void
    {
        Http::fake();
        $workspace = $this->workspace();
        $this->connect($workspace);
        $this->activity($workspace, 'restricted.jpg');
        Gate::define('studio.integrations.manage', fn () => false);
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertNothingSent();
        self::assertSame('access_revoked', DB::table('fms_slack_connections')->value('last_error'));
        self::assertSame(0, DB::table('fms_slack_connections')->value('enabled'));
    }

    public function test_viewers_cannot_manage_and_private_events_cannot_be_subscribed(): void
    {
        $workspace = $this->workspace();
        $this->putJson($this->url($workspace, 'integrations/slack'), ['revision' => 0, 'channel' => '#team', 'webhook' => self::WEBHOOK, 'enabled' => true, 'events' => ['mentioned']])->assertUnprocessable();
        $this->connect($workspace);
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->getJson($this->url($workspace, 'integrations'))->assertOk()->assertJsonPath('canManage', false);
        $this->deleteJson($this->url($workspace, 'integrations/slack'), ['revision' => 1])->assertForbidden();
        $this->activity($workspace, 'restricted.jpg');
        $this->artisan('media-studio:integrations:slack')->assertSuccessful();
        Http::assertNothingSent();
    }
}
