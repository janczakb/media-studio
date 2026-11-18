<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationThumbnails;
use UseFlex\MediaStudio\ApplicationFiles\GenerateApplicationThumbnail;
use UseFlex\MediaStudio\Identity\Principal;

final class ApplicationThumbnailsTest extends TestCase
{
    private const BASE = '/media-studio/api/v1/application-files/catalog';

    protected function setUp(): void
    {
        parent::setUp();
        if (! GdImageRenderer::supports('webp')) {
            self::markTestSkipped('GD WebP is unavailable.');
        }
        foreach (['application-migrations', 'application-thumbnail-migrations'] as $directory) {
            foreach (glob(__DIR__.'/../packages/laravel/database/'.$directory.'/*.php') as $migration) {
                (require $migration)->up();
            }
        }
        config()->set('filesystems.disks.studio-test', ['driver' => 'local', 'root' => storage_path('app/studio-test')]);
        config()->set('filesystems.disks.application-cache', ['driver' => 'local', 'root' => storage_path('app/application-cache')]);
        Storage::fake('application-cache');
        config()->set('media-studio.application_files', [
            'enabled' => true,
            'sources' => ['post' => ['type' => 'disk', 'allow_unregistered' => true, 'revision' => 'v1', 'disk' => 'studio-test', 'prefix' => 'posts']],
            'thumbnails' => ['enabled' => true, 'disk' => 'application-cache', 'format' => 'webp', 'connection' => 'database',
                'driver' => 'gd', 'edge' => 128, 'max_cache_bytes' => 65536, 'max_output_bytes' => 65536, 'max_cache_entries' => 1, 'ttl_minutes' => 5],
        ]);
        Gate::define('studio.application-files.source.post', fn () => true);
    }

    private function fixture(string $name): string
    {
        $path = 'posts/'.$name.'.png';
        $upload = UploadedFile::fake()->image($name.'.png', 300, 150);
        $original = file_get_contents($upload->getPathname());
        Storage::disk('studio-test')->put($path, $original);
        app(ApplicationCatalog::class)->indexDisk(Principal::fromUser(auth()->user()), 'post', [$path]);

        return $original;
    }

    public function test_visible_requests_are_deduplicated_and_private_cache_is_bounded_and_reclaimed(): void
    {
        Queue::fake();
        $original = $this->fixture('cover');
        $this->fixture('other');
        [$file, $other] = $this->getJson(self::BASE)->assertOk()->assertJsonPath('items.0.thumbnailState', 'queued')->json('items');
        Queue::assertNothingPushed();
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id'], $other['id']]])->assertOk();
        Queue::assertPushed(GenerateApplicationThumbnail::class, 1);
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['entries' => 1, 'bytes' => 65536, 'pressure' => true]);

        $job = Queue::pushed(GenerateApplicationThumbnail::class)->sole();
        $job->handle(app(ApplicationThumbnails::class));
        $job->handle(app(ApplicationThumbnails::class));
        $file = $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk()->assertJsonPath('items.0.thumbnailState', 'ready')->json('items.0');
        $bytes = $this->get($file['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp')->streamedContent();
        $dimensions = getimagesizefromstring($bytes);
        self::assertSame([128, 64], [$dimensions[0], $dimensions[1]]);
        self::assertSame($original, $this->get($file['previewUrl'])->assertOk()->streamedContent());
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['entries' => 1, 'bytes' => strlen($bytes)]);
        Gate::define('studio.application-files.source.post', fn () => false);
        $this->get($file['thumbnailUrl'])->assertNotFound();
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$other['id']]])->assertOk()->assertJsonCount(0, 'items');
        Gate::define('studio.application-files.source.post', fn () => true);
        $this->artisan('media-studio:application-files:prune-thumbnails')->assertSuccessful();
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['entries' => 0, 'bytes' => 0, 'pressure' => false]);
        self::assertSame([], Storage::disk('application-cache')->allFiles());
        $this->get($file['thumbnailUrl'])->assertNotFound();
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$other['id']]])->assertOk();
        Queue::assertPushed(GenerateApplicationThumbnail::class, 2);
    }

    public function test_explicit_cli_prepares_thumbnails_without_a_worker_and_requires_indexing_authority(): void
    {
        Queue::fake();
        $this->fixture('cover');
        config()->set('media-studio.application_files.thumbnails.connection', 'sync');
        $this->artisan('media-studio:application-files:warm-thumbnails', [
            'source' => 'post', 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', '--limit' => 1,
        ])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('items.0.thumbnailState', 'ready');
        $this->assertDatabaseHas('fms_application_thumbnails', ['state' => 'ready']);
        Gate::define('studio.application-files.index', fn () => false);
        $this->expectException(HttpException::class);
        $this->artisan('media-studio:application-files:warm-thumbnails', [
            'source' => 'post', 'principal-type' => 'user', 'principal-id' => 'member-uuid-001',
        ])->run();
    }

    public function test_source_replacement_and_profile_changes_fence_queued_results(): void
    {
        Queue::fake();
        $this->fixture('cover');
        $file = $this->getJson(self::BASE)->assertOk()->json('items.0');
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        config()->set('media-studio.application_files.thumbnails.edge', 256);
        Queue::pushed(GenerateApplicationThumbnail::class)->sole()->handle(app(ApplicationThumbnails::class));
        $this->assertDatabaseHas('fms_application_thumbnails', ['state' => 'failed']);
        self::assertSame([], Storage::disk('application-cache')->allFiles());
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['bytes' => 65536, 'entries' => 1]);
    }

    public function test_expiry_and_failed_delete_keep_storage_charged_until_cleanup_succeeds(): void
    {
        Queue::fake();
        $this->fixture('cover');
        $file = $this->getJson(self::BASE)->assertOk()->json('items.0');
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        Queue::pushed(GenerateApplicationThumbnail::class)->sole()->handle(app(ApplicationThumbnails::class));
        $ready = DB::table('fms_application_thumbnails')->first();
        $this->travel(6)->minutes();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with($ready->object_key)->andReturn(false);
        Storage::shouldReceive('disk')->with('application-cache')->once()->andReturn($disk);
        self::assertSame(0, app(ApplicationThumbnails::class)->prune(10));
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['entries' => 1, 'bytes' => $ready->bytes]);
        $this->assertDatabaseHas('fms_application_thumbnails', ['state' => 'failed']);
    }

    public function test_revocation_after_queueing_blocks_generation_and_sync_never_encodes_inline(): void
    {
        Queue::fake();
        $this->fixture('cover');
        $file = $this->getJson(self::BASE)->assertOk()->json('items.0');
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        Gate::define('studio.application-files.view', fn () => false);
        Queue::pushed(GenerateApplicationThumbnail::class)->sole()->handle(app(ApplicationThumbnails::class));
        self::assertSame([], Storage::disk('application-cache')->allFiles());
        $this->assertDatabaseHas('fms_application_thumbnails', ['state' => 'failed']);
        DB::table('fms_application_thumbnails')->update(['expires_at' => now()->subMinute()]);
        $this->artisan('media-studio:application-files:prune-thumbnails')->assertSuccessful();
        $this->assertDatabaseHas('fms_application_thumbnail_budget', ['entries' => 0, 'bytes' => 0]);
        Gate::define('studio.application-files.view', fn () => true);
        config()->set('media-studio.application_files.thumbnails.connection', 'sync');
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('items.0.thumbnailState', 'none');
        $this->postJson(self::BASE.'/thumbnail-status', ['ids' => [$file['id']]])->assertOk();
        Queue::assertPushed(GenerateApplicationThumbnail::class, 1);
    }
}
