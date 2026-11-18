<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Sources\DiskIndexer;

final class DiskRenditionsTest extends TestCase
{
    private function source(): array
    {
        Bus::fake([GenerateThumbnail::class]);
        Storage::fake('archive');
        $image = imagecreatetruecolor(80, 40);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('archive')->put('old/photo.png', $bytes);
        config()->set('media-studio.sources.mounts.archive', ['disk' => 'archive', 'prefix' => 'old', 'authorize' => fn () => true]);
        $workspace = $this->workspace();
        app(DiskIndexer::class)->importPage(new Principal('user', 'member-uuid-001'), $workspace, 'archive', ['old/photo.png'], true);

        return [$workspace, $bytes, ['workspace' => $workspace, 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', '--source' => 'disk', '--apply' => true]];
    }

    public function test_opt_in_backfill_generates_private_derivatives_without_mutating_originals(): void
    {
        [$workspace,$original,$args] = $this->source();
        Artisan::call('media-studio:renditions:backfill', $args);
        $this->assertSame(0, DB::table('fms_renditions')->count());
        config()->set('media-studio.sources.mounts.archive.renditions', true);
        config()->set('media-studio.processing.originals.keep', false);
        $this->assertSame(0, Artisan::call('media-studio:renditions:backfill', $args));
        $this->assertSame(2, DB::table('fms_renditions')->count());
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $entry = DB::table('fms_entries')->where('kind', 'file')->first();
        $this->assertSame('ready', $entry->thumbnail_state);
        $this->assertSame('studio-test', $entry->thumbnail_disk);
        $response = $this->get($this->url($workspace, 'entries/'.$entry->public_id.'/thumbnail'))->assertOk()->streamedContent();
        $this->assertSame('image/webp', getimagesizefromstring($response)['mime']);
        $this->assertSame($original, Storage::disk('archive')->get('old/photo.png'));
        $this->assertSame(['old/photo.png'], Storage::disk('archive')->allFiles());
        $this->assertSame(0, DB::table('fms_original_retirements')->count());
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        Artisan::call('media-studio:renditions:backfill', $args);
        $this->assertSame(2, DB::table('fms_renditions')->count());
        config()->set('media-studio.sources.mounts.archive.authorize', fn () => false);
        $this->get($this->url($workspace, 'entries/'.$entry->public_id.'/thumbnail'))->assertNotFound();
        $this->get($this->url($workspace, 'entries/'.$entry->public_id.'/preview'))->assertNotFound();
    }

    public function test_source_change_during_render_prevents_publication_and_cleans_unpublished_outputs(): void
    {
        [$workspace, $original, $args] = $this->source();
        config()->set('media-studio.sources.mounts.archive.renditions', true);
        Artisan::call('media-studio:renditions:backfill', $args);
        $base = Storage::disk('studio-test');
        Storage::set('studio-test', new MutatingSourceRenditionDisk($base->getDriver(), $base->getAdapter(), $base->getConfig()));
        try {
            Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
            $this->fail('A source changed during generation cannot publish derivatives.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_renditions')->where('state', 'ready')->count());
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
        $this->assertSame(['old/photo.png'], Storage::disk('archive')->allFiles());
    }

    public function test_worker_rejects_changed_or_disabled_source_before_rendering(): void
    {
        [$workspace,$original,$args] = $this->source();
        config()->set('media-studio.sources.mounts.archive.renditions', true);
        Artisan::call('media-studio:renditions:backfill', $args);
        Storage::disk('archive')->put('old/photo.png', 'changed');
        try {
            Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
            $this->fail('Changed external originals must not be processed.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_renditions')->where('state', 'ready')->count());
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
        Storage::disk('archive')->put('old/photo.png', $original);
        config()->set('media-studio.sources.mounts.archive.renditions', false);
        $this->expectException(HttpException::class);
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
    }
}

final class MutatingSourceRenditionDisk extends FilesystemAdapter
{
    public function writeStream($path, $resource, array $options = [])
    {
        $result = parent::writeStream($path, $resource, $options);
        Storage::disk('archive')->put('old/photo.png', 'replaced outside Studio');

        return $result;
    }
}
