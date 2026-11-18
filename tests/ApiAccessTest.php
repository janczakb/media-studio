<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ApiAccessTest extends TestCase
{
    private function issue(string $workspace, string $scope = 'read'): string
    {
        config(['media-studio.api.enabled' => true, 'media-studio.api.usage_cache_seconds' => 0]);

        return $this->postJson($this->url($workspace, 'api-access/tokens'), ['name' => 'Integration', 'scope' => $scope, 'days' => 30])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('token');
    }

    private function api(string $workspace, string $suffix = 'entries'): string
    {
        return '/media-studio/rest/v1/workspaces/'.$workspace.'/'.$suffix;
    }

    public function test_secret_is_shown_once_and_cookie_auth_never_authenticates_the_rest_api(): void
    {
        $w = $this->workspace();
        $token = $this->issue($w);
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonCount(1, 'items')->assertDontSee($token);
        self::assertStringNotContainsString(explode('.', $token)[1], json_encode(DB::table('fms_api_tokens')->first()));
        $this->getJson($this->api($w))->assertUnauthorized();
        $this->withToken('fms_mcp_invalid.secret')->getJson($this->api($w))->assertUnauthorized();
        $this->withToken($token)->getJson($this->api($w))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 1)->assertJsonPath('usage.29.successes', 1);
    }

    public function test_read_token_cannot_write_or_cross_workspace_and_grants_do_not_replace_domain_permissions(): void
    {
        $w = $this->workspace();
        $other = $this->workspace('Other');
        $token = $this->issue($w);
        $this->withToken($token)->postJson($this->api($w, 'folders'), ['name' => 'Denied'])->assertForbidden();
        $this->getJson($this->api($other))->assertNotFound();
        self::assertSame(0, DB::table('fms_entries')->count());
        $write = $this->issue($w, 'write');
        $this->withToken($write)->postJson($this->api($w, 'folders'), ['name' => 'Accepted'])->assertCreated();
        Gate::define('studio.folders.create', static fn () => false);
        $this->postJson($this->api($w, 'folders'), ['name' => 'Denied again'])->assertForbidden();
        self::assertSame(1, DB::table('fms_entries')->count());
        Gate::define('studio.api.use', static fn () => false);
        $this->getJson($this->api($w))->assertForbidden();
    }

    public function test_revocation_expiry_owner_isolation_and_usage_errors(): void
    {
        $w = $this->workspace();
        $token = $this->issue($w);
        $id = DB::table('fms_api_tokens')->value('public_id');
        $this->withToken($token)->postJson($this->api($w, 'folders'), ['name' => 'Denied'])->assertForbidden();
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 1)->assertJsonPath('usage.29.errors', 1);
        $this->deleteJson($this->url($w, 'api-access/tokens/'.$id))->assertOk();
        $this->getJson($this->api($w))->assertUnauthorized();
        $second = $this->issue($w);
        $this->travel(31)->days();
        $this->withToken($second)->getJson($this->api($w))->assertUnauthorized();
        $this->travelBack();
        $this->actingAs(new GenericUser(['id' => 'stranger']));
        $this->getJson($this->url($w, 'api-access'))->assertNotFound();
        $this->deleteJson($this->url($w, 'api-access/tokens/'.$id))->assertNotFound();
    }

    public function test_disabled_feature_validation_and_rate_limit_are_enforced(): void
    {
        $w = $this->workspace();
        $this->postJson($this->url($w, 'api-access/tokens'), ['name' => 'Integration', 'scope' => 'read', 'days' => 30])->assertServiceUnavailable();
        $token = $this->issue($w);
        $this->postJson($this->url($w, 'api-access/tokens'), ['name' => 'Bad', 'scope' => 'admin', 'days' => 366])->assertUnprocessable()->assertJsonValidationErrors(['scope', 'days']);
        config(['media-studio.api.requests_per_minute' => 1]);
        $this->withToken($token)->getJson($this->api($w))->assertOk();
        $this->getJson($this->api($w))->assertStatus(429)->assertHeader('Retry-After');
        config(['media-studio.api.enabled' => false]);
        $this->getJson($this->api($w))->assertNotFound();
    }

    public function test_chunk_sessions_are_bound_to_the_token_and_publish_readable_bytes(): void
    {
        config(['media-studio.upload.sessions.enabled' => true]);
        $w = $this->workspace();
        $first = $this->issue($w, 'write');
        $second = $this->issue($w, 'write');
        $url = $this->api($w, 'upload-sessions');
        $session = $this->withToken($first)->postJson($url, ['name' => 'api.txt', 'size' => 3, 'key' => 'api-session-isolated-0001'])->assertCreated()->json('id');
        $this->withToken($second)->getJson($url.'/'.$session)->assertNotFound();
        $this->withToken($first)->getJson($url.'/'.$session)->assertOk();
        $this->call('PUT', $url.'/'.$session.'/parts/0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$first], 'abc')->assertOk();
        $entry = $this->postJson($url.'/'.$session.'/complete')->assertOk()->json();
        $this->getJson($this->api($w, 'entries/'.$entry['id']))->assertOk();
        $response = $this->get($this->api($w, 'entries/'.$entry['id'].'/content'), ['Accept' => 'application/json']);
        $response->assertOk();
        self::assertSame('abc', $response->streamedContent());
        $this->getJson($this->api($w))->assertOk()->assertJsonPath('items.0.name', 'api.txt');
    }

    public function test_statistical_cache_is_scoped_expires_and_does_not_scan_files(): void
    {
        $w = $this->workspace();
        $token = $this->issue($w);
        config(['media-studio.api.usage_cache_seconds' => 10]);
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 0);
        $this->withToken($token)->getJson($this->api($w))->assertOk();
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 0);
        $this->travel(11)->seconds();
        DB::enableQueryLog();
        $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 1)->assertJsonCount(30, 'usage');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        self::assertStringNotContainsString('fms_entries', $queries);
        self::assertStringNotContainsString('fms_asset_versions', $queries);
        DB::disableQueryLog();
        $this->travelBack();
        $this->artisan('media-studio:api:prune')->assertSuccessful();
    }
}
