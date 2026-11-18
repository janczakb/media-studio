<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Minishlink\WebPush\VAPID;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Activity\BrowserPush;
use UseFlex\MediaStudio\Activity\PushDelivery;
use UseFlex\MediaStudio\Identity\Principal;

final class BrowserPushTest extends TestCase
{
    private function enable(): void
    {
        $keys = VAPID::createVapidKeys();
        config([
            'media-studio.notifications.push.enabled' => true,
            'media-studio.notifications.push.public_key' => $keys['publicKey'],
            'media-studio.notifications.push.private_key' => $keys['privateKey'],
            'media-studio.notifications.push.subject' => 'mailto:owner@example.test',
        ]);
    }

    private function subscription(string $suffix = 'device'): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'keys' => ['p256dh' => VAPID::createVapidKeys()['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
            'path' => '/studio', 'locale' => 'pl',
        ];
    }

    private function activity(string $workspace, string $key = 'new-push-event'): void
    {
        $record = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $entry = DB::table('fms_entries')->where('workspace_id', $record->id)->first();
        if ($entry === null) {
            $this->postJson($this->url($workspace, 'folders'), ['name' => 'Private folder title'])->assertCreated();
            $entry = DB::table('fms_entries')->where('workspace_id', $record->id)->first();
        }
        app(ActivityLog::class)->record($record, new Principal('user', 'member-uuid-001'), $entry, 'processed', $key);
    }

    public function test_push_accepts_regional_languages_without_a_two_language_allowlist(): void
    {
        $workspace = $this->workspace();
        $this->enable();
        $subscription = [...$this->subscription(), 'locale' => 'zh-Hant-TW'];
        $this->postJson($this->url($workspace, 'notification-push/devices'), $subscription)->assertOk();
        $this->assertDatabaseHas('fms_push_devices', ['locale' => 'zh-Hant-TW']);
        $this->postJson($this->url($workspace, 'notification-push/devices'), [...$subscription, 'locale' => '../private'])->assertUnprocessable();
    }

    public function test_device_subscription_is_encrypted_scoped_and_disabled_without_configuration(): void
    {
        $workspace = $this->workspace();
        $url = $this->url($workspace, 'notification-push');
        $data = $this->subscription();
        $hash = hash('sha256', $data['endpoint']);
        $this->getJson($url)->assertOk()->assertJsonPath('available', false)->assertJsonPath('publicKey', null);
        $this->postJson($url.'/devices', $data)->assertServiceUnavailable();

        $this->enable();
        $this->postJson($url.'/devices', $data)->assertOk()->assertJsonPath('subscribed', true);
        $stored = DB::table('fms_push_devices')->first();
        self::assertStringNotContainsString('googleapis', $stored->subscription);
        self::assertSame($data['endpoint'], json_decode(Crypt::decryptString($stored->subscription), true)['endpoint']);
        $this->getJson($url.'?device='.$hash)->assertOk()->assertJsonPath('subscribed', true)->assertHeader('Cache-Control', 'no-store, private');

        $other = new Principal('user', 'other-member');
        DB::table('fms_memberships')->insert([
            'workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'),
            'principal_type' => $other->type, 'principal_id' => $other->id, 'principal_key' => $other->key(), 'role' => 'viewer',
        ]);
        $this->actingAs(new GenericUser(['id' => $other->id]));
        $this->getJson($url.'?device='.$hash)->assertOk()->assertJsonPath('subscribed', false);
        $this->deleteJson($url.'/devices/'.$hash)->assertOk();
        self::assertSame(1, DB::table('fms_push_devices')->count());
        Gate::define('studio.notifications.view', static fn () => false);
        $this->putJson($url.'/preferences', ['events' => []])->assertForbidden();
        $this->getJson($url)->assertForbidden();
    }

    public function test_preferences_apply_to_only_the_current_principal_and_workspace(): void
    {
        $this->enable();
        $first = $this->workspace('One');
        $second = $this->workspace('Two');
        $url = $this->url($first, 'notification-push');
        $this->putJson($url.'/preferences', ['events' => ['mentioned', 'processed']])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('events', ['mentioned', 'processed']);
        $this->getJson($this->url($second, 'notification-push'))->assertOk()->assertJsonPath('events', ['mentioned', 'guest_uploaded', 'processed']);
        $this->putJson($url.'/preferences', ['events' => []])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('events', []);
        $this->putJson($url.'/preferences', ['events' => ['unsupported']])->assertUnprocessable();
        $this->putJson($url.'/preferences', ['events' => ['mentioned', 'mentioned']])->assertUnprocessable();
        self::assertSame(1, DB::table('fms_push_preferences')->count());
    }

    public function test_endpoint_validation_blocks_private_hosts_and_redirect_tricks_and_bounds_devices(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        $url = $this->url($workspace, 'notification-push/devices');
        $data = $this->subscription();
        foreach (['http://fcm.googleapis.com/send/one', 'https://127.0.0.1/one', 'https://fcm.googleapis.com.evil.test/one', 'https://fcm.googleapis.com@evil.test/one', 'https://fcm.googleapis.com:444/one', 'https://evil.test/one'] as $endpoint) {
            $this->postJson($url, [...$data, 'endpoint' => $endpoint])->assertUnprocessable();
        }
        foreach (['//evil.test', '/studio?redirect=evil', 'https://evil.test'] as $path) {
            $this->postJson($url, [...$data, 'path' => $path])->assertUnprocessable();
        }
        $this->postJson($url, [...$data, 'keys' => ['p256dh' => 'invalid', 'auth' => $data['keys']['auth']]])->assertUnprocessable();
        self::assertSame(0, DB::table('fms_push_devices')->count());
        $this->travel(61)->seconds();
        for ($i = 0; $i < BrowserPush::DEVICE_LIMIT; $i++) {
            $this->postJson($url, [...$data, 'endpoint' => $data['endpoint'].$i])->assertOk();
        }
        $this->postJson($url, [...$data, 'endpoint' => $data['endpoint'].'extra'])->assertUnprocessable();
        $this->postJson($url, [...$data, 'endpoint' => $data['endpoint'].'0'])->assertOk();
        self::assertSame(BrowserPush::DEVICE_LIMIT, DB::table('fms_push_devices')->count());
    }

    public function test_activity_replay_schedules_only_one_private_push_and_opt_out_is_rechecked_before_delivery(): void
    {
        $this->freezeTime();
        $this->enable();
        $workspace = $this->workspace();
        $data = $this->subscription();
        $this->postJson($this->url($workspace, 'notification-push/devices'), $data)->assertOk();
        $this->activity($workspace);
        $this->activity($workspace);
        self::assertSame(1, DB::table('fms_push_deliveries')->count());
        $sender = $this->mock(PushDelivery::class);
        $sender->shouldReceive('send')->once()->withArgs(function (array $subscription, array $payload, int $ttl) use ($data, $workspace): bool {
            self::assertSame($data['endpoint'], $subscription['endpoint']);
            self::assertSame('/studio', $payload['path']);
            self::assertSame($workspace, $payload['workspace']);
            self::assertSame('Masz nowe powiadomienie w Studio.', $payload['body']);
            self::assertArrayNotHasKey('name', $payload);
            self::assertSame(1800, $ttl);

            return true;
        })->andReturn(201);
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
        $this->activity($workspace, 'next-event');
        $this->putJson($this->url($workspace, 'notification-push/preferences'), ['events' => []])->assertOk();
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
        $this->activity($workspace, 'disabled-event');
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
    }

    public function test_temporary_delivery_failure_retries_but_expired_subscription_is_removed(): void
    {
        $this->freezeTime();
        $this->enable();
        $workspace = $this->workspace();
        $data = $this->subscription();
        $this->postJson($this->url($workspace, 'notification-push/devices'), $data)->assertOk();
        $this->activity($workspace);
        $sender = $this->mock(PushDelivery::class);
        $sender->shouldReceive('send')->once()->andReturn(503);
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        $delivery = DB::table('fms_push_deliveries')->first();
        self::assertSame(1, (int) $delivery->attempts);
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        $this->travel(61)->seconds();
        $sender->shouldReceive('send')->once()->andReturn(410);
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        self::assertSame(0, DB::table('fms_push_devices')->count());
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
    }

    public function test_worker_rechecks_access_and_read_state_and_disable_removes_pending_notifications(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        $data = $this->subscription();
        $url = $this->url($workspace, 'notification-push');
        $this->postJson($url.'/devices', $data)->assertOk();
        $this->activity($workspace);
        $this->mock(PushDelivery::class)->shouldNotReceive('send');
        Gate::define('studio.notifications.view', static fn () => false);
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
        Gate::define('studio.notifications.view', static fn () => true);
        $this->activity($workspace, 'read-event');
        $this->postJson($this->url($workspace, 'notifications/read-all'))->assertOk();
        $this->artisan('media-studio:notifications:deliver')->assertSuccessful();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
        $this->activity($workspace, 'removed-device-event');
        self::assertSame(1, DB::table('fms_push_deliveries')->count());
        $this->deleteJson($url.'/devices/'.hash('sha256', $data['endpoint']))->assertOk();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
    }

    public function test_rollback_discards_deliveries_and_public_worker_has_no_auth_redirect(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        $this->postJson($this->url($workspace, 'notification-push/devices'), $this->subscription())->assertOk();
        DB::beginTransaction();
        $this->activity($workspace);
        self::assertSame(1, DB::table('fms_push_deliveries')->count());
        DB::rollBack();
        self::assertSame(0, DB::table('fms_push_deliveries')->count());
        $this->get('/media-studio/api/v1/push/worker.js')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_transport_sends_real_encrypted_aes128gcm_and_vapid_without_following_redirects(): void
    {
        $this->enable();
        $data = $this->subscription();
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(201)]));
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler, 'allow_redirects' => false]);
        $payload = ['body' => 'Private push transport probe', 'workspace' => 'workspace'];
        self::assertSame(201, (new PushDelivery($client))->send($data, $payload, 60));
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame($data['endpoint'], (string) $request->getUri());
        self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('60', $request->getHeaderLine('TTL'));
        self::assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString($payload['body'], (string) $request->getBody());
        self::assertGreaterThan(86, strlen((string) $request->getBody()));
    }
}
