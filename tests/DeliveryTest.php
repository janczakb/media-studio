<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use UseFlex\MediaStudio\Delivery\Adapters\BunnyUrl;
use UseFlex\MediaStudio\Delivery\Adapters\CloudFrontUrl;
use UseFlex\MediaStudio\Delivery\Delivery;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Delivery\DeliveryUrl;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;

final class DeliveryTest extends TestCase
{
    private function profile(): void
    {
        config()->set('media-studio.delivery', [
            'enabled' => true, 'roles' => ['original' => 'images', 'thumbnail' => 'images', 'preview' => 'images'],
            'profiles' => ['images' => ['driver' => 'bunny', 'url' => 'https://images.example.invalid/assets', 'token_key' => str_repeat('k', 32), 'revocation' => 'expiry', 'ttl_seconds' => 120]],
        ]);
    }

    private function upload(string $workspace): array
    {
        return $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('image.png', 32, 24)])->assertCreated()->json();
    }

    public function test_cdn_redirect_preserves_authorization_and_does_not_stream_original_bytes(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $this->profile();
        $url = $this->url($workspace, 'entries/'.$file['id'].'/content');
        $response = $this->get($url, ['Range' => 'bytes=0-3'])->assertStatus(307);
        $this->assertStringStartsWith('https://images.example.invalid/assets/', $response->headers->get('Location'));
        $this->assertStringContainsString('token=HS256-', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Gate::define('studio.view', fn () => false);
        $this->getJson($url)->assertForbidden()->assertHeaderMissing('Location');
    }

    public function test_strict_mode_defaults_to_server_and_download_disposition_is_not_weakened(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $this->profile();
        $url = $this->url($workspace, 'entries/'.$file['id'].'/content');
        $this->get($url.'?download=1')->assertDownload('image.png');
        config()->set('media-studio.delivery.profiles.images.revocation', 'per_request');
        $this->get($url, ['Range' => 'bytes=0-3'])->assertStatus(206)->assertStreamedContent("\x89PNG");
    }

    public function test_thumbnail_and_named_rendition_delivery_use_profiles_after_source_checks(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $this->profile();
        $this->get($this->url($workspace, 'entries/'.$file['id'].'/thumbnail'))->assertStatus(307);
        config()->set('media-studio.delivery.roles.rendition', 'images');
        $rendition = DB::table('fms_renditions')->where('preset', 'thumbnail-v1')->first();
        $path = $this->url($workspace, 'entries/'.$file['id'].'/versions/'.$rendition->version_id.'/renditions/thumbnail-v1');
        $this->get($path)->assertStatus(307);
        config()->set('media-studio.delivery.presets.thumbnail-v1', 'server');
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->app['auth']->forgetGuards();
        $this->getJson($path)->assertUnauthorized()->assertHeaderMissing('Location');
    }

    public function test_cloudfront_signature_verifies_independently_and_binds_complete_resource(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $resource = new DeliveryResource('disk', 'folder/a b.png', 'image/png', 'a.png', 'workspace-a', 'version-a', 'thumbnail');
        $url = (new CloudFrontUrl)->url($resource, ['url' => 'https://cdn.example.invalid', 'private_key' => $pem, 'key_pair_id' => 'K123'], 1900000000);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $unsigned = explode('&Expires=', $url)[0];
        $policy = json_encode(['Statement' => [['Resource' => $unsigned, 'Condition' => ['DateLessThan' => ['AWS:EpochTime' => 1900000000]]]]], JSON_UNESCAPED_SLASHES);
        $signature = base64_decode(strtr($query['Signature'], ['-' => '+', '_' => '=', '~' => '/']));
        $this->assertSame(1, openssl_verify($policy, $signature, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
        $this->assertSame('SHA256', $query['Hash-Algorithm']);
        $this->assertStringContainsString('a%20b.png', $url);
        $other = new DeliveryResource('disk', 'folder/a b.png', 'image/png', 'a.png', 'workspace-b', 'version-a', 'thumbnail');
        $this->assertNotSame($resource->cacheKey(), $other->cacheKey());
    }

    public function test_bunny_signs_encoded_path_and_exact_version_without_directory_access(): void
    {
        $resource = new DeliveryResource('disk', 'folder/ż a.png', 'image/png', 'a.png', 'workspace', 'version', 'thumbnail');
        $url = (new BunnyUrl)->url($resource, ['url' => 'https://cdn.example.invalid/root', 'token_key' => str_repeat('k', 32)], 1900000000);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $message = parse_url($url, PHP_URL_PATH).'1900000000'.'fmsv='.$resource->cacheKey();
        $this->assertSame('HS256-'.DeliveryUrl::base64(hash_hmac('sha256', $message, str_repeat('k', 32), true)), $query['token']);
        $this->assertArrayNotHasKey('token_path', $query);
    }

    public function test_workspace_and_preset_overrides_have_deterministic_precedence(): void
    {
        $this->profile();
        $base = config('media-studio.delivery.profiles.images');
        foreach (['disk', 'workspace', 'preset'] as $name) {
            config()->set('media-studio.delivery.profiles.'.$name, [...$base, 'url' => 'https://'.$name.'.example.invalid']);
        }
        config()->set('media-studio.delivery.disks.source.thumbnail', 'disk');
        config()->set('media-studio.delivery.workspaces.company.thumbnail', 'workspace');
        config()->set('media-studio.delivery.presets.custom', 'preset');
        $resource = new DeliveryResource('source', 'x.png', 'image/png', 'x', 'company', 'v', 'thumbnail', 'custom');
        $this->assertSame('preset.example.invalid', parse_url(app(Delivery::class)->redirect($resource)->getTargetUrl(), PHP_URL_HOST));
    }

    public function test_public_delivery_requires_explicit_acknowledgement_and_invalid_profiles_fail_closed(): void
    {
        $this->profile();
        config()->set('media-studio.delivery.profiles.images', ['driver' => 'public', 'revocation' => 'public', 'url' => 'https://cdn.example.invalid']);
        $resource = new DeliveryResource('source', 'x.png', 'image/png', 'x', 'company', 'v', 'original');
        $this->expectException(LogicException::class);
        app(Delivery::class)->redirect($resource);
    }

    public function test_guest_worker_grant_stops_after_revocation_and_never_outlives_the_share(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $expires = now()->addSeconds(30)->startOfSecond();
        $share = $this->postJson($this->url($workspace, 'shares'), ['title' => 'Edge collection', 'entryIds' => [$file['id']], 'expiresAt' => $expires->toIso8601String()])->assertCreated()->json();
        $this->profile();
        config()->set('media-studio.delivery.profiles.images', ['driver' => 'worker', 'url' => 'https://edge.example.invalid', 'token_key' => str_repeat('k', 32), 'revocation' => 'per_request', 'ttl_seconds' => 120]);
        $this->app['auth']->forgetGuards();
        $response = $this->get($share['url'].'/entries/'.$file['id'].'/preview')->assertStatus(307);
        $this->assertSame((string) $expires->getTimestamp(), $response->headers->get('X-FMS-Delivery-Expires'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $ticket = json_decode(base64_decode(strtr($query['ticket'], '-_', '+/')), true);
        $check = '/media-studio/api/v1/delivery/check';
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertNoContent();
        $this->assertStringContainsString('https://edge.example.invalid', $this->get($share['url'])->headers->get('Content-Security-Policy'));
        DB::table('fms_shares')->where('public_id', $share['id'])->update(['revoked_at' => now()]);
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertForbidden();
        $this->travel(31)->seconds();
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertForbidden();
    }

    public function test_strict_worker_grant_rechecks_permissions_and_trash(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $this->profile();
        config()->set('media-studio.delivery.profiles.images', ['driver' => 'worker', 'url' => 'https://edge.example.invalid', 'token_key' => str_repeat('k', 32), 'revocation' => 'per_request']);
        $url = $this->url($workspace, 'entries/'.$file['id'].'/content');
        $target = $this->get($url)->assertStatus(307)->headers->get('Location');
        parse_str(parse_url($target, PHP_URL_QUERY), $query);
        $ticket = json_decode(base64_decode(strtr($query['ticket'], '-_', '+/')), true);
        $check = '/media-studio/api/v1/delivery/check';
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertNoContent();
        Gate::define('studio.view', fn () => false);
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertForbidden();
        Gate::define('studio.view', fn () => true);
        DB::table('fms_entries')->where('public_id', $file['id'])->update(['trashed_at' => now()]);
        $this->postJson($check, [], ['X-FMS-Grant' => $ticket['grant']])->assertForbidden();
        $this->postJson($check, [], ['X-FMS-Grant' => 'invalid'])->assertForbidden();
    }
}
