<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Psr\Http\Message\RequestInterface;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMedia;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMediaObject;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationThumbnails;
use UseFlex\MediaStudio\ApplicationFiles\GenerateApplicationThumbnail;
use UseFlex\MediaStudio\ApplicationFiles\RegistersApplicationMedia;
use UseFlex\MediaStudio\Identity\Principal;

final class ApplicationThumbnailS3Test extends TestCase
{
    public function test_private_s3_original_to_private_s3_thumbnail_and_cleanup_use_the_real_signed_adapter(): void
    {
        if (! GdImageRenderer::supports('webp')) {
            self::markTestSkipped('GD WebP is unavailable.');
        }
        foreach (['application-migrations', 'application-thumbnail-migrations'] as $directory) {
            foreach (glob(__DIR__.'/../../packages/laravel/database/'.$directory.'/*.php') as $migration) {
                (require $migration)->up();
            }
        }
        Schema::create('s3_thumbnail_posts', fn (Blueprint $table) => $table->id());
        $post = S3ThumbnailPost::create();
        $image = UploadedFile::fake()->image('cover.png', 400, 200);
        $original = file_get_contents($image->getPathname());
        config()->set('fixtures.original_bytes', strlen($original));
        $handler = new MockHandler;
        config()->set('filesystems.disks.thumbnail-s3', [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'studio-private', 'region' => 'eu-north-1',
            'root' => 'tenant-root', 'endpoint' => 'https://storage.example.invalid', 'use_path_style_endpoint' => true,
            'key' => 'fixture', 'secret' => 'fixture-secret', 'handler' => $handler,
        ]);
        config()->set('media-studio.application_files', [
            'enabled' => true,
            'sources' => ['posts' => ['type' => 'model', 'model' => S3ThumbnailPost::class, 'revision' => 'v1',
                'stores' => ['thumbnail-s3' => 'flat'], 'scope' => fn (Builder $query) => $query]],
            'thumbnails' => ['enabled' => true, 'disk' => 'thumbnail-s3', 'connection' => 'database', 'format' => 'webp', 'edge' => 100, 'driver' => 'gd', 'ttl_minutes' => 5],
        ]);
        Gate::define('studio.application-files.source.posts', fn () => true);
        app(ApplicationCatalog::class)->syncModel(Principal::fromUser(auth()->user()), 'posts', (string) $post->getKey());
        $base = '/media-studio/api/v1/application-files/catalog';
        $file = $this->getJson($base)->assertOk()->json('items.0');
        Queue::fake();
        $this->postJson($base.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        $encoded = null;
        $cacheKey = null;
        $handler->append(function (CommandInterface $command, RequestInterface $request) use ($original): Result {
            self::assertSame('GetObject', $command->getName());
            self::assertSame('tenant-root/flat/cover.png', $command['Key']);
            self::assertStringContainsString('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));

            return new Result(['Body' => Utils::streamFor($original)]);
        });
        $handler->append(function (CommandInterface $command, RequestInterface $request) use (&$encoded, &$cacheKey): Result {
            self::assertSame('PutObject', $command->getName());
            self::assertSame('private', $command['ACL']);
            self::assertStringContainsString('AWS4-HMAC-SHA256', $request->getHeaderLine('Authorization'));
            $cacheKey = $command['Key'];
            self::assertStringStartsWith('tenant-root/flex-media-studio/application-thumbnails/', $cacheKey);
            $encoded = Utils::streamFor($command['Body'])->getContents();
            $info = getimagesizefromstring($encoded);
            self::assertSame([100, 50, 'image/webp'], [$info[0], $info[1], $info['mime']]);

            return new Result(['ETag' => '"thumbnail"']);
        });
        Queue::pushed(GenerateApplicationThumbnail::class)->sole()->handle(app(ApplicationThumbnails::class));
        $this->assertDatabaseHas('fms_application_thumbnails', ['state' => 'ready']);
        $file = $this->postJson($base.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk()->json('items.0');
        $handler->append(function (CommandInterface $command) use (&$encoded, &$cacheKey): Result {
            self::assertSame('GetObject', $command->getName());
            self::assertSame($cacheKey, $command['Key']);

            return new Result(['Body' => Utils::streamFor($encoded)]);
        });
        self::assertSame($encoded, $this->get($file['thumbnailUrl'])->assertOk()->streamedContent());
        $handler->append(function (CommandInterface $command) use (&$cacheKey): Result {
            self::assertSame('DeleteObject', $command->getName());
            self::assertSame($cacheKey, $command['Key']);
            self::assertNotSame('tenant-root/flat/cover.png', $command['Key']);

            return new Result;
        });
        $this->travel(6)->minutes();
        self::assertSame(1, app(ApplicationThumbnails::class)->prune(10));
        self::assertSame(0, DB::table('fms_application_thumbnail_budget')->value('bytes'));
        $this->get($file['thumbnailUrl'])->assertNotFound();
        self::assertSame(0, count($handler));
    }
}

final class S3ThumbnailPost extends Model implements RegistersApplicationMedia
{
    protected $table = 's3_thumbnail_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function studioMedia(): iterable
    {
        yield new ApplicationMedia('cover', 'v1', new ApplicationMediaObject('thumbnail-s3', 'flat/cover.png', 'image/png', config('fixtures.original_bytes')));
    }
}
