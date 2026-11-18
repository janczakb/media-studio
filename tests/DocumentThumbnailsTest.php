<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\DocumentThumbnails\DocumentThumbnails;
use UseFlex\MediaStudio\DocumentThumbnails\OfficeThumbnailGenerator;
use UseFlex\MediaStudio\DocumentThumbnails\PdfThumbnailGenerator;
use UseFlex\MediaStudio\DocumentThumbnails\TextThumbnailGenerator;
use UseFlex\MediaStudio\DocumentThumbnails\ThumbnailGenerator;
use UseFlex\MediaStudio\DocumentThumbnails\VipsPdfThumbnailGenerator;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;

final class DocumentThumbnailsTest extends TestCase
{
    private function uploadDocument(string $workspace): array
    {
        return $this->withHeader('Idempotency-Key', 'document-test-'.bin2hex(random_bytes(12)))->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent('document.pdf', file_get_contents(__DIR__.'/fixtures/documents/portrait.pdf'))])->assertCreated()->json();
    }

    public function test_disabled_does_not_admit_document_work(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $this->uploadDocument($this->workspace());
        self::assertSame(0, DB::table('fms_renditions')->count());
        Bus::assertNotDispatched(GenerateThumbnail::class);
    }

    public function test_command_mode_generates_only_a_thumbnail_and_preserves_original(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.formats.application/pdf.generator', FixtureDocumentGenerator::class);
        Bus::fake([GenerateThumbnail::class]);
        $w = $this->workspace();
        $file = $this->uploadDocument($w);
        Bus::assertNotDispatched(GenerateThumbnail::class);
        self::assertSame(1, DB::table('fms_renditions')->count());
        $version = DB::table('fms_asset_versions')->first();
        $before = Storage::disk($version->disk)->get($version->object_key);
        $this->artisan('media-studio:renditions:reconcile', ['--documents' => true])->assertSuccessful();
        $row = DB::table('fms_renditions')->first();
        self::assertSame('ready', $row->state);
        self::assertSame('image/webp', $row->mime_type);
        self::assertSame([240, 480], [(int) $row->width, (int) $row->height]);
        self::assertSame($before, Storage::disk($version->disk)->get($version->object_key));
        $this->getJson($this->url($w, 'entries/'.$file['id']))->assertOk()->assertJsonPath('thumbnailState', 'ready')->assertJsonPath('previewUrl', null);
    }

    public function test_queue_sync_never_runs_a_document_conversion_inside_upload(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.execution', 'queue');
        config()->set('media-studio.processing.document_thumbnails.connection', 'sync');
        Bus::fake([GenerateThumbnail::class]);
        $this->uploadDocument($this->workspace());
        Bus::assertNotDispatched(GenerateThumbnail::class);
        self::assertSame('queued', DB::table('fms_renditions')->value('state'));
    }

    public function test_real_pdf_generates_a_bounded_first_page_raster(): void
    {
        $binary = '/opt/homebrew/bin/pdftoppm';
        if (! is_executable($binary)) {
            $this->markTestSkipped('Poppler is not installed.');
        }
        config()->set('media-studio.processing.document_thumbnails.pdf_binary', $binary);
        $stream = app(PdfThumbnailGenerator::class)->rasterize(__DIR__.'/fixtures/documents/portrait.pdf', ['width' => 480, 'timeout' => 10, 'max_raster_bytes' => 8388608]);
        try {
            $info = getimagesizefromstring(stream_get_contents($stream));
            self::assertSame([240, 480], array_slice($info, 0, 2));
        } finally {
            fclose($stream);
        }
    }

    public function test_vips_backend_encodes_pdf_directly_and_publishes_a_verified_thumbnail(): void
    {
        if (! is_executable('/opt/homebrew/bin/vips')) {
            $this->markTestSkipped('libvips CLI is unavailable.');
        }
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.pdf_backend', 'vips');
        config()->set('media-studio.processing.document_thumbnails.vips_binary', '/opt/homebrew/bin/vips');
        $w = $this->workspace();
        $file = $this->uploadDocument($w);
        $row = DB::table('fms_renditions')->first();
        $recipe = json_decode($row->recipe, true);
        self::assertSame(VipsPdfThumbnailGenerator::class, $recipe['document_generator']);
        self::assertSame(1, $recipe['vips_concurrency']);
        $this->artisan('media-studio:renditions:reconcile', ['--documents' => true])->assertSuccessful();
        $row = DB::table('fms_renditions')->first();
        self::assertSame('ready', $row->state, $row->error_code ?? '');
        self::assertSame('image/webp', $row->mime_type);
        self::assertLessThanOrEqual(480, max($row->width, $row->height));
        self::assertGreaterThan(0, $row->size_bytes);
        $this->getJson($this->url($w, 'entries/'.$file['id']))->assertOk()->assertJsonPath('thumbnailState', 'ready');
    }

    public function test_vips_backend_can_encode_avif_without_the_php_image_encoder(): void
    {
        if (! is_executable('/opt/homebrew/bin/vips')) {
            $this->markTestSkipped('libvips CLI is unavailable.');
        }
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.pdf_backend', 'vips');
        config()->set('media-studio.processing.document_thumbnails.vips_binary', '/opt/homebrew/bin/vips');
        config()->set('media-studio.processing.document_thumbnails.format', 'avif');
        $profile = app(DocumentThumbnails::class)->recipe((object) ['mime_type' => 'application/pdf', 'size_bytes' => 100]);
        $stream = app(VipsPdfThumbnailGenerator::class)->rasterize(__DIR__.'/fixtures/documents/portrait.pdf', $profile);
        try {
            $info = getimagesize(stream_get_meta_data($stream)['uri']);
            self::assertSame('image/avif', $info['mime']);
            self::assertLessThanOrEqual(480, max($info[0], $info[1]));
        } finally {
            fclose($stream);
        }
    }

    public function test_missing_vips_does_not_publish_a_partial_thumbnail(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.pdf_backend', 'vips');
        config()->set('media-studio.processing.document_thumbnails.vips_binary', '/missing/vips');
        $this->uploadDocument($this->workspace());
        try {
            $this->artisan('media-studio:renditions:reconcile', ['--documents' => true])->run();
        } catch (\Throwable) {
            // The command reports the renderer failure; the database must retain a retryable failure.
        }
        $row = DB::table('fms_renditions')->first();
        self::assertSame('failed', $row->state);
        self::assertNull($row->object_key);
    }

    public function test_invalid_pdf_backend_is_rejected(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.pdf_backend', 'unknown');
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentThumbnails::class)->recipe((object) ['mime_type' => 'application/pdf', 'size_bytes' => 100]);
    }

    public function test_text_generator_produces_a_small_raster_without_executing_html(): void
    {
        $font = '/System/Library/Fonts/Supplemental/Arial.ttf';
        if (! is_file($font)) {
            $this->markTestSkipped('Unicode test font is unavailable.');
        }
        config()->set('media-studio.processing.document_thumbnails.text_font', $font);
        $input = tmpfile();
        fwrite($input, '<script>fetch("http://127.0.0.1/private")</script><h1>Zażółć gęślą jaźń</h1><p>Thumbnail</p>');
        $stream = app(TextThumbnailGenerator::class)->rasterize(stream_get_meta_data($input)['uri'], ['width' => 480]);
        try {
            self::assertSame([480, 480], array_slice(getimagesizefromstring(stream_get_contents($stream)), 0, 2));
        } finally {
            fclose($input);
            fclose($stream);
        }
    }

    public function test_office_generator_requires_explicit_isolated_worker_configuration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('document_isolation_required');
        app(OfficeThumbnailGenerator::class)->rasterize('/not-read', []);
    }

    public function test_real_office_fixture_produces_only_a_small_raster(): void
    {
        $finder = new \Symfony\Component\Process\ExecutableFinder;
        $binary = getenv('MEDIA_STUDIO_TEST_SOFFICE') ?: ($finder->find('soffice') ?? $finder->find('libreoffice') ?? '');
        $pdfBinary = $finder->find('pdftoppm') ?? '';
        if (! is_executable($binary) || ! is_executable($pdfBinary)) {
            $this->markTestSkipped('Office integration tools are unavailable.');
        }
        // Trusted fixture: this exercises conversion, not container/network isolation.
        config()->set('media-studio.processing.document_thumbnails.office_isolated_worker', true);
        config()->set('media-studio.processing.document_thumbnails.office_binary', $binary);
        config()->set('media-studio.processing.document_thumbnails.pdf_binary', $pdfBinary);
        $stream = app(OfficeThumbnailGenerator::class)->rasterize(__DIR__.'/fixtures/documents/sample.docx', ['source_extension' => 'docx', 'width' => 480, 'timeout' => 30, 'max_raster_bytes' => 8388608]);
        try {
            $info = getimagesizefromstring(stream_get_contents($stream));
            self::assertLessThanOrEqual(480, max($info[0], $info[1]));
            self::assertSame('image/png', $info['mime']);
        } finally {
            fclose($stream);
        }
    }

    public function test_disabled_pending_work_does_not_execute_or_advertise_polling(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        $w = $this->workspace();
        $file = $this->uploadDocument($w);
        config()->set('media-studio.processing.document_thumbnails.enabled', false);
        $this->artisan('media-studio:renditions:reconcile', ['--documents' => true])->assertSuccessful();
        self::assertSame(0, (int) DB::table('fms_renditions')->value('attempts'));
        $this->getJson($this->url($w, 'entries/'.$file['id']))->assertOk()->assertJsonPath('thumbnailState', 'none');
    }

    public function test_model_profile_overrides_output_without_changing_global_settings(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.model_profiles.CompanyDocument', ['edge' => 600, 'format' => 'avif', 'quality' => 55]);
        $entry = (object) ['mime_type' => 'application/pdf', 'size_bytes' => 100, 'name' => 'page.pdf'];
        $profile = app(DocumentThumbnails::class)->recipe($entry, 'CompanyDocument');
        self::assertSame([600, 'avif', 55], [$profile['width'], $profile['format'], $profile['quality']]);
        self::assertSame(480, app(DocumentThumbnails::class)->recipe($entry)['width']);
    }

    public function test_profile_is_bounded_and_inline_requires_explicit_admission(): void
    {
        config()->set('media-studio.processing.document_thumbnails.enabled', true);
        config()->set('media-studio.processing.document_thumbnails.execution', 'inline');
        $this->expectException(\InvalidArgumentException::class);
        app(DocumentThumbnails::class)->recipe((object) ['mime_type' => 'application/pdf', 'size_bytes' => 100]);
    }
}

final class FixtureDocumentGenerator implements ThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        $image = imagecreatetruecolor(240, 480);
        $output = tmpfile();
        imagepng($image, $output);
        rewind($output);

        return $output;
    }
}
