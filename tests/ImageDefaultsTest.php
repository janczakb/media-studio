<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaCore\Images\EncodedImageVerifier;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Renditions\ImagePresets;

final class ImageDefaultsTest extends TestCase
{
    private function configureDefaults(bool $keep): void
    {
        $defaults = require __DIR__.'/../packages/laravel/config/media-studio.php';
        config()->set('media-studio.processing', $defaults['processing']);
        config()->set('media-studio.processing.originals.keep', $keep);
    }

    private function uploadAndProcess(string $workspace): object
    {
        Bus::fake([GenerateThumbnail::class]);
        $this->withHeader('Idempotency-Key', 'default-image-'.bin2hex(random_bytes(8)))->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('landscape.png', 2400, 1200),
        ])->assertCreated();
        $version = DB::table('fms_asset_versions')->first();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));

        return $version;
    }

    public function test_shipped_defaults_generate_three_avif_sizes_and_keep_original(): void
    {
        $this->configureDefaults(true);
        $version = $this->uploadAndProcess($this->workspace());
        $rows = DB::table('fms_renditions')->where('version_id', $version->id)->orderBy('width')->get();
        self::assertSame([400, 1000, 1900], $rows->pluck('width')->map(fn ($n) => (int) $n)->all());
        self::assertSame([200, 500, 950], $rows->pluck('height')->map(fn ($n) => (int) $n)->all());
        self::assertSame([40, 40, 50], $rows->map(fn ($row) => json_decode($row->recipe, true)['quality'])->all());
        self::assertSame([3, 3, 3], $rows->map(fn ($row) => json_decode($row->recipe, true)['avif_effort'])->all());
        foreach ($rows as $row) {
            self::assertSame('image/avif', $row->mime_type);
            self::assertSame('ready', $row->state);
            $bytes = Storage::disk($row->disk)->get($row->object_key);
            self::assertSame('image/avif', getimagesizefromstring($bytes)['mime']);
            self::assertSame('ftyp', substr($bytes, 4, 4));
            self::assertStringContainsString('avif', substr($bytes, 8, 48));
            if (extension_loaded('imagick')) {
                $decoded = new \Imagick;
                $decoded->readImageBlob($bytes);
                self::assertSame((int) $row->width, $decoded->getImageWidth());
                foreach (['exif', 'icc', 'xmp', 'iptc'] as $profile) {
                    self::assertSame([], $decoded->getImageProfiles($profile));
                }
                $decoded->clear();
            } else {
                $decoded = imagecreatefromstring($bytes);
                self::assertNotFalse($decoded);
                self::assertSame((int) $row->width, imagesx($decoded));
                imagedestroy($decoded);
            }
        }
        self::assertTrue(Storage::disk($version->disk)->exists($version->object_key));
        self::assertSame($version->id, DB::table('fms_entries')->value('current_version_id'));
    }

    public function test_discard_policy_publishes_new_master_before_delayed_original_deletion(): void
    {
        $this->configureDefaults(false);
        $w = $this->workspace();
        $version = $this->uploadAndProcess($w);
        $entry = DB::table('fms_entries')->first();
        self::assertNotSame($version->id, $entry->current_version_id);
        self::assertSame('image/avif', $entry->mime_type);
        self::assertStringEndsWith('.avif', $entry->name);
        self::assertTrue(Storage::disk($version->disk)->exists($version->object_key));
        $this->travel(2)->days();
        $this->artisan('media-studio:originals:prune', ['workspace' => $w, 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', '--apply' => true])->assertSuccessful();
        self::assertFalse(Storage::disk($version->disk)->exists($version->object_key));
        $current = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
        self::assertTrue(Storage::disk($current->disk)->exists($current->object_key));
        self::assertSame('ready', DB::table('fms_renditions')->where('version_id', $current->id)->value('state'));
    }

    public function test_original_is_not_retired_when_required_conversion_is_missing(): void
    {
        $this->configureDefaults(false);
        config()->set('media-studio.processing.originals.replacement_preset', 'not-generated');
        $version = $this->uploadAndProcess($this->workspace());
        self::assertSame($version->id, DB::table('fms_entries')->value('current_version_id'));
        self::assertSame(0, DB::table('fms_original_retirements')->count());
        self::assertTrue(Storage::disk($version->disk)->exists($version->object_key));
    }

    public function test_small_images_are_not_enlarged(): void
    {
        $this->configureDefaults(true);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $this->withHeader('Idempotency-Key', 'small-image-test-000001')->postJson($this->url($w, 'uploads'), ['file' => UploadedFile::fake()->image('small.png', 80, 40)])->assertCreated();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        foreach (DB::table('fms_renditions')->get() as $row) {
            self::assertSame([80, 40], [(int) $row->width, (int) $row->height]);
        }
    }

    public function test_invalid_avif_effort_is_rejected(): void
    {
        $this->configureDefaults(true);
        config()->set('media-studio.processing.renditions.thumbnail-v1.avif_effort', 10);
        $this->expectException(\InvalidArgumentException::class);
        ImagePresets::configured();
    }

    public function test_small_image_profiles_share_one_large_quality_object_and_keep_all_roles(): void
    {
        $this->configureDefaults(true);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'deduplicate-image-00001')->postJson($this->url($w, 'uploads'), ['file' => UploadedFile::fake()->image('small.png', 300, 200)])->assertCreated()->json();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $rows = DB::table('fms_renditions')->get();
        self::assertCount(3, $rows);
        self::assertCount(1, $rows->pluck('object_key')->unique());
        self::assertCount(1, $rows->pluck('sha256')->unique());
        self::assertStringEndsWith('/large-v1', $rows->first()->object_key);
        self::assertCount(2, Storage::disk('studio-test')->allFiles()); // original + one AVIF
        $this->getJson($this->url($w, 'entries/'.$file['id']))->assertOk()->assertJsonPath('thumbnailState', 'ready')->assertJsonPath('previewState', 'ready');
        $this->assertSame('image/avif', getimagesizefromstring(Storage::disk('studio-test')->get($rows->first()->object_key))['mime']);
    }

    public function test_deduplication_can_be_disabled(): void
    {
        $this->configureDefaults(true);
        config()->set('media-studio.processing.native.deduplicate', false);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $this->withHeader('Idempotency-Key', 'separate-image-00001')->postJson($this->url($w, 'uploads'), ['file' => UploadedFile::fake()->image('small.png', 80, 40)])->assertCreated();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        self::assertCount(3, DB::table('fms_renditions')->pluck('object_key')->unique());
    }

    public function test_opted_out_variant_keeps_its_own_object(): void
    {
        $this->configureDefaults(true);
        config()->set('media-studio.processing.renditions.medium-v1.deduplicate', false);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $this->withHeader('Idempotency-Key', 'individual-profile-00001')->postJson($this->url($w, 'uploads'), ['file' => UploadedFile::fake()->image('small.png', 80, 40)])->assertCreated();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $rows = DB::table('fms_renditions')->get()->keyBy('preset');
        self::assertSame($rows['large-v1']->object_key, $rows['thumbnail-v1']->object_key);
        self::assertNotSame($rows['large-v1']->object_key, $rows['medium-v1']->object_key);
    }

    public function test_retirement_cleanup_preserves_a_shared_object(): void
    {
        $this->configureDefaults(true);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $this->withHeader('Idempotency-Key', 'protected-shared-00001')->postJson($this->url($w, 'uploads'), ['file' => UploadedFile::fake()->image('small.png', 80, 40)])->assertCreated();
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $row = DB::table('fms_renditions')->first();
        DB::table('fms_retired_renditions')->insert(['workspace_id' => DB::table('fms_workspaces')->value('id'), 'disk' => $row->disk, 'object_key' => $row->object_key, 'retired_at' => now()->subDays(2), 'delete_after' => now()->subDay()]);
        $this->artisan('media-studio:renditions:prune', ['workspace' => $w, '--apply' => true])->assertSuccessful();
        self::assertTrue(Storage::disk($row->disk)->exists($row->object_key));
        self::assertSame(3, DB::table('fms_renditions')->where('object_key', $row->object_key)->count());
    }

    public function test_fake_avif_is_rejected_based_on_its_bytes(): void
    {
        $image = imagecreatetruecolor(20, 10);
        $stream = tmpfile();
        imagepng($image, $stream);
        try {
            $this->expectException(\RuntimeException::class);
            app(EncodedImageVerifier::class)->verify($stream, 'image/avif', ['width' => 20, 'height' => 10]);
        } finally {
            fclose($stream);
        }
    }
}
