<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaCore\Images\ImagickImageRenderer;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;

final class StudioMediaProcessingTest extends TestCase
{
    public function test_imagick_writes_the_requested_format_for_a_named_jpeg_source(): void
    {
        if (! extension_loaded('imagick') || \Imagick::queryFormats('AVIF') === [] || \Imagick::queryFormats('WEBP') === []) {
            $this->markTestSkipped('The local Imagick build needs AVIF and WebP encoders.');
        }

        $renderer = app(ImagickImageRenderer::class);
        $image = $renderer->open(__DIR__.'/fixtures/coastal-adventure.jpg', 16000000);
        try {
            foreach (['avif', 'webp'] as $format) {
                $preset = new ImagePreset($format, [
                    'width' => 300, 'height' => 300, 'fit' => 'contain', 'format' => $format,
                    'quality' => 40, 'upscale' => false, 'position' => 'center', 'background' => '#ffffff',
                ]);
                $output = tmpfile();
                try {
                    $dimensions = $renderer->render($image, $preset, $output);
                    $info = getimagesize(stream_get_meta_data($output)['uri']);
                    self::assertSame(['width' => 300, 'height' => 200], $dimensions);
                    self::assertSame(['image/'.$format, 300, 200], [$info['mime'], $info[0], $info[1]]);
                } finally {
                    fclose($output);
                }
            }
        } finally {
            $image->clear();
        }
    }

    public function test_imagick_creates_webp_and_avif_with_independent_sizes_and_quality(): void
    {
        if (! extension_loaded('imagick') || \Imagick::queryFormats('AVIF') === [] || \Imagick::queryFormats('WEBP') === []) {
            $this->markTestSkipped('The local Imagick build needs AVIF and WebP encoders.');
        }

        config()->set([
            'media-studio.processing.native.driver' => 'imagick',
            'media-studio.processing.renditions.thumbnail-v1' => ['width' => 80, 'height' => 80, 'format' => 'webp', 'quality' => 65],
            'media-studio.processing.renditions.preview-v1' => ['width' => 160, 'height' => 160, 'format' => 'avif', 'quality' => 51, 'avif_effort' => 6],
        ]);
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'imagick-studio-000001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('source.png', 200, 100),
        ])->assertCreated()->json();
        app(ThumbnailProcessor::class)->execute($workspace, $file['versionId'], new Principal('user', 'member-uuid-001'));
        $recipes = DB::table('fms_renditions')->where('version_id', $file['versionId'])->pluck('recipe', 'preset');
        self::assertSame(6, json_decode($recipes['thumbnail-v1'], true)['avif_effort']);
        self::assertSame(6, json_decode($recipes['preview-v1'], true)['avif_effort']);
        $this->getJson($this->url($workspace, 'entries/'.$file['id']))->assertOk()
            ->assertJsonPath('renditions.0.mimeType', 'image/avif')->assertJsonPath('renditions.0.width', 160)
            ->assertJsonPath('renditions.1.mimeType', 'image/webp')->assertJsonPath('renditions.1.height', 40);
    }

    public function test_metadata_policy_is_captured_per_conversion_and_preserves_descriptive_profiles(): void
    {
        if (! extension_loaded('imagick') || \Imagick::queryFormats('AVIF') === []) {
            $this->markTestSkipped('ImageMagick with AVIF is required for metadata preservation.');
        }

        $source = tmpfile();
        $image = new \Imagick;
        try {
            $image->newImage(240, 120, new \ImagickPixel('#557799'), 'jpeg');
            $artist = "Studio Test\0";
            $exif = "Exif\0\0II".pack('vVv', 42, 8, 2)
                .pack('vvVv', 0x0112, 3, 1, 6)."\0\0"
                .pack('vvVV', 0x013B, 2, strlen($artist), 38).pack('V', 0).$artist;
            $image->profileImage('exif', $exif);
            $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
                .'<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator><rdf:Seq><rdf:li>Studio Test</rdf:li></rdf:Seq></dc:creator>'
                .'</rdf:Description></rdf:RDF></x:xmpmeta>';
            $image->profileImage('xmp', $xmp);
            $image->setImageOrientation(\Imagick::ORIENTATION_RIGHTTOP);
            $image->writeImageFile($source);
            $originalBytes = file_get_contents(stream_get_meta_data($source)['uri']);
            config()->set('media-studio.processing.native.strip_metadata', false);
            config()->set('media-studio.processing.renditions', [
                'thumbnail-v1' => ['width' => 100, 'height' => 100, 'fit' => 'pad', 'format' => 'webp'],
                'preview-v1' => ['width' => 200, 'height' => 200, 'format' => 'avif'],
                'clean-webp' => ['width' => 100, 'height' => 100, 'format' => 'webp', 'strip_metadata' => true],
                'clean-avif' => ['width' => 100, 'height' => 100, 'format' => 'avif', 'strip_metadata' => true],
            ]);
            Bus::fake([GenerateThumbnail::class]);
            $workspace = $this->workspace();
            $file = $this->withHeader('Idempotency-Key', 'metadata-preservation-test')->postJson($this->url($workspace, 'uploads'), [
                'file' => new UploadedFile(stream_get_meta_data($source)['uri'], 'authored.jpg', null, null, true),
            ])->assertCreated()->json();
            config()->set('media-studio.processing.native.strip_metadata', true);
            Bus::dispatched(GenerateThumbnail::class)->last()->handle(app(ThumbnailProcessor::class));
            foreach (DB::table('fms_renditions')->get() as $variant) {
                $read = new \Imagick;
                try {
                    $read->readImageBlob(Storage::disk($variant->disk)->get($variant->object_key));
                    if (in_array($variant->preset, ['preview-v1', 'clean-avif'], true)) {
                        $expectedDimensions = $variant->preset === 'preview-v1' ? [100, 200] : [50, 100];
                        self::assertSame($expectedDimensions, [$read->getImageWidth(), $read->getImageHeight()]);
                    }
                    if (str_starts_with($variant->preset, 'clean-')) {
                        $this->assertSame([], $read->getImageProfiles('exif'));
                        $this->assertSame([], $read->getImageProfiles('xmp'));
                    } else {
                        $this->assertStringContainsString('Studio Test', $read->getImageProfile('xmp'));
                        $this->assertSame('Studio Test', $read->getImageProperty('exif:Artist'));
                        $this->assertSame('1', $read->getImageProperty('exif:Orientation'));
                        $this->assertContains($read->getImageProperty('exif:ImageWidth'), [false, (string) $read->getImageWidth()]);
                        $this->assertContains($read->getImageProperty('exif:ImageLength'), [false, (string) $read->getImageHeight()]);
                    }
                } finally {
                    $read->clear();
                }
            }
            $version = DB::table('fms_asset_versions')->where('id', $file['versionId'])->first();
            $this->assertSame($originalBytes, Storage::disk($version->disk)->get($version->object_key));
        } finally {
            $image->clear();
            fclose($source);
        }
    }

    public function test_explicit_gd_metadata_preservation_fails_without_publishing_stripped_files(): void
    {
        config()->set('media-studio.processing.native.driver', 'gd');
        config()->set('media-studio.processing.native.strip_metadata', false);
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'gd-metadata-test-00001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('authored.png', 100, 100),
        ])->assertCreated()->json();
        try {
            Bus::dispatched(GenerateThumbnail::class)->last()->handle(app(ThumbnailProcessor::class));
            $this->fail('GD must explicitly reject metadata preservation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('metadata_requires_imagick', $error->getMessage());
        }
        $this->assertSame(0, DB::table('fms_renditions')->where('state', 'ready')->count());
        $this->assertSame(2, DB::table('fms_renditions')->where('failure_code', 'metadata_requires_imagick')->count());
        $original = DB::table('fms_asset_versions')->where('id', $file['versionId'])->first();
        $this->assertSame($original->sha256, hash('sha256', Storage::disk($original->disk)->get($original->object_key)));
    }

    public static function videoPosters(): array
    {
        return [
            'WebP clean' => ['webp', 'libwebp', true],
            'AVIF clean' => ['avif', 'libaom-av1', true],
            'WebP metadata' => ['webp', 'libwebp', false],
            'AVIF metadata' => ['avif', 'libaom-av1', false],
        ];
    }

    #[DataProvider('videoPosters')]
    public function test_real_mov_upload_queues_poster_and_compressed_playback_and_keeps_original(string $format, string $encoder, bool $stripMetadata): void
    {
        $configured = config('media-studio.processing.video.ffmpeg');
        $ffmpeg = $configured ?? (new ExecutableFinder)->find('ffmpeg');
        if ($ffmpeg === null) {
            $this->markTestSkipped('FFmpeg is required for the real video acceptance case.');
        }
        $encoders = (new Process([$ffmpeg, '-hide_banner', '-encoders']))->mustRun()->getOutput();
        if ($configured === null && ! preg_match('/^\s*V\S*\s+'.preg_quote($encoder, '/').'\s/m', $encoders)) {
            $this->markTestSkipped('FFmpeg encoder '.$encoder.' is required. Configure FLEX_MEDIA_STUDIO_FFMPEG to test a full build.');
        }

        $sample = tmpfile();
        $path = stream_get_meta_data($sample)['uri'];
        try {
            (new Process([$ffmpeg, '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=80x60:d=0.5', '-c:v', 'mpeg4', '-metadata', 'title=Studio original title', '-f', 'mov', $path]))->mustRun();
            config()->set([
                'media-studio.processing.connection' => 'database',
                'queue.connections.database.driver' => 'database',
                'queue.connections.database.connection' => 'testing',
                'queue.connections.database.table' => 'studio_test_jobs',
                'queue.failed.driver' => null,
                'media-studio.processing.queue' => 'media-video-test',
                'media-studio.processing.video.enabled' => true,
                'media-studio.processing.video.compress' => true,
                'media-studio.processing.video.ffmpeg' => $ffmpeg,
                'media-studio.processing.video.poster_format' => $format,
                'media-studio.processing.video.strip_metadata' => $stripMetadata,
            ]);
            Schema::create('studio_test_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
            $workspace = $this->workspace();
            $file = $this->withHeader('Idempotency-Key', 'video-studio-000001')->postJson($this->url($workspace, 'uploads'), [
                'file' => new UploadedFile($path, 'sample.mov', null, null, true),
            ])->assertCreated()->assertJsonPath('previewState', 'queued')->assertJsonPath('thumbnailState', 'queued')->json();
            $this->assertDatabaseCount('studio_test_jobs', 1);
            $source = DB::table('fms_asset_versions')->where('id', $file['versionId'])->first();
            $hash = hash_file('sha256', $path);
            $this->artisan('queue:work', [
                'connection' => 'database', '--queue' => 'media-video-test', '--once' => true, '--tries' => 1, '--timeout' => 120,
            ])->assertSuccessful();
            $this->assertDatabaseCount('studio_test_jobs', 0);
            $entry = 'entries/'.$file['id'];
            $this->getJson($this->url($workspace, $entry))->assertOk()
                ->assertJsonPath('previewState', 'ready')->assertJsonPath('thumbnailState', 'ready');
            $this->get($this->url($workspace, $entry.'/thumbnail'))->assertOk()->assertHeader('Content-Type', 'image/'.$format);
            $this->get($this->url($workspace, $entry.'/preview'), ['Range' => 'bytes=0-31'])
                ->assertStatus(206)->assertHeader('Content-Type', 'video/mp4')->assertHeader('Content-Length', '32');
            $this->assertSame($hash, hash('sha256', Storage::disk($source->disk)->get($source->object_key)));
            $playback = DB::table('fms_renditions')->where('version_id', $file['versionId'])->where('mime_type', 'video/mp4')->sole();
            $probe = new Process([dirname($ffmpeg).'/ffprobe', '-v', 'error', '-show_entries', 'format_tags=title',
                '-of', 'json', Storage::disk($playback->disk)->path($playback->object_key)]);
            $probe->mustRun();
            $title = json_decode($probe->getOutput(), true, 8, JSON_THROW_ON_ERROR)['format']['tags']['title'] ?? null;
            $this->assertSame($stripMetadata ? null : 'Studio original title', $title);
        } finally {
            fclose($sample);
        }
    }
}
