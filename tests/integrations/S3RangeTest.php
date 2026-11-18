<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMedia;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMediaObject;
use UseFlex\MediaStudio\ApplicationFiles\RegistersApplicationMedia;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\UploadAsset;

final class S3RangeTest extends TestCase
{
    public function test_registered_application_media_reads_only_the_authorized_private_s3_variant(): void
    {
        foreach (glob(__DIR__.'/../../packages/laravel/database/application-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        Schema::create('s3_media_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner');
        });
        $handler = new MockHandler;
        config()->set('filesystems.disks.post-media', [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'studio-private',
            'region' => 'eu-north-1', 'endpoint' => 'https://storage.example.invalid',
            'root' => 'prefix', 'use_path_style_endpoint' => true,
            'key' => 'fixture', 'secret' => 'fixture-secret', 'handler' => $handler,
        ]);
        config()->set('media-studio.application_files', ['enabled' => true, 'sources' => [
            'posts' => ['type' => 'model', 'model' => S3MediaPost::class, 'revision' => 'v1', 'permission' => 'application.posts',
                'stores' => ['post-media' => 'flat'],
                'scope' => fn (Builder $query, ?TenantIdentity $tenant, Principal $actor) => $query->where('owner', $actor->id)],
        ]]);
        Gate::define('application.posts', fn () => true);
        $principal = Principal::fromUser(auth()->user());
        $post = S3MediaPost::create(['owner' => $principal->id]);
        app(ApplicationCatalog::class)->syncModel($principal, 'posts', (string) $post->getKey());
        $path = '/media-studio/api/v1/application-files/catalog?source=posts';
        $file = $this->getJson($path)->assertOk()->assertJsonCount(1, 'items')->json('items.0');

        $handler->append(function (CommandInterface $command, RequestInterface $request): Result {
            self::assertSame('GetObject', $command->getName());
            self::assertSame('prefix/flat/cover-320.avif', $command['Key']);
            self::assertStringContainsString('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));

            return new Result(['Body' => Utils::streamFor('thumbnail')]);
        });
        $response = $this->get($file['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/avif');
        self::assertSame('thumbnail', $response->streamedContent());
        $post->update(['owner' => 'another-user']);
        $this->get($file['thumbnailUrl'])->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_private_version_route_sends_a_signed_range_for_the_prefixed_s3_object(): void
    {
        $handler = new MockHandler;
        config()->set('filesystems.disks.range-test', [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'studio-private',
            'region' => 'eu-north-1', 'endpoint' => 'https://storage.example.invalid',
            'root' => 'prefix', 'use_path_style_endpoint' => true,
            'key' => 'fixture', 'secret' => 'fixture-secret', 'handler' => $handler,
        ]);
        config()->set('media-studio.storage.disk', 'range-test');

        $workspaceId = $this->workspace();
        $principal = Principal::fromUser(auth()->user());
        $workspace = app(WorkspaceAccess::class)->resolve($principal, $workspaceId);
        $version = (string) Str::ulid();
        $key = 'flex-media-studio/'.$workspaceId.'/originals/'.$version;
        $content = '0123456789';
        $entry = app(UploadAsset::class)->publishVerifiedObject($workspace, $principal, null, 'range.txt',
            'text/plain', strlen($content), hash('sha256', $content), $version, $key);
        $url = $this->url($workspaceId, 'entries/'.$entry->public_id.'/versions/'.$version.'/content');

        $handler->append(function (CommandInterface $command, RequestInterface $request) use ($key): Result {
            self::assertSame('GetObject', $command->getName());
            self::assertSame('prefix/'.$key, $command['Key']);
            self::assertSame('/studio-private/prefix/'.$key, $request->getUri()->getPath());
            self::assertSame('bytes=2-5', $request->getHeaderLine('Range'));
            self::assertStringContainsString('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));

            return new Result(['Body' => Utils::streamFor('2345')]);
        });

        $response = $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4')
            ->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame('2345', $response->streamedContent());
        $this->get($url, ['Range' => 'bytes=90-'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
        $this->actingAs(new GenericUser(['id' => 'unrelated-user']))->get($url, ['Range' => 'bytes=2-5'])->assertNotFound();
    }
}

final class S3MediaPost extends Model implements RegistersApplicationMedia
{
    protected $table = 's3_media_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function studioMedia(): iterable
    {
        yield new ApplicationMedia('cover', 'v1', new ApplicationMediaObject('post-media', 'flat/cover.jpg', 'image/jpeg', 12),
            variants: [320 => new ApplicationMediaObject('post-media', 'flat/cover-320.avif', 'image/avif', 9)], thumbnail: '320');
    }
}
