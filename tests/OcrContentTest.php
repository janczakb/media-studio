<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Search\ExtractTextContent;
use UseFlex\MediaStudio\Search\OcrImage;
use UseFlex\MediaStudio\Search\PdfTextExtractor;

final class OcrContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/search-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        config(['media-studio.search.content.enabled' => true, 'media-studio.search.content.ocr.enabled' => true, 'media-studio.search.content.ocr.images' => true, 'media-studio.intelligence.providers.google_vision.key' => 'ocr-test-key']);
        Http::preventStrayRequests();
    }

    public function test_ocr_is_independent_of_visual_tags_and_publishes_current_version_text(): void
    {
        $workspace = $this->workspace();
        $upload = $this->withHeader('Idempotency-Key', 'ocr-image-upload-001')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('sign.png', 32, 16)])->assertCreated()->json();
        $id = $upload['entry']['id'] ?? $upload['id'];
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => "Łódź\nHotel nad morzem"]]]])]);

        $this->assertSame('ready', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame('ready', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        config(['media-studio.search.content.queue' => 'another-queue', 'media-studio.search.content.automatic' => true]);
        $this->assertSame('ready', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        Http::assertSentCount(1);
        $row = DB::table('fms_search_content')->first();
        $this->assertSame('Łódź Hotel nad morzem', $row->content);
        $this->assertSame(DB::table('fms_entries')->where('public_id', $id)->value('current_version_id'), $row->version_id);
        Http::assertSent(fn ($request) => $request['requests'][0]['features'][0]['type'] === 'DOCUMENT_TEXT_DETECTION');
        $this->assertFalse(config('media-studio.intelligence.enabled'));
    }

    public function test_empty_ocr_is_reported_as_no_text_without_fabricating_content(): void
    {
        $workspace = $this->workspace();
        $upload = $this->withHeader('Idempotency-Key', 'ocr-empty-image-001')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('blank.png', 32, 16)])->assertCreated()->json();
        $id = $upload['entry']['id'] ?? $upload['id'];
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [(object) []]])]);
        $this->assertSame('no_text', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame('', DB::table('fms_search_content')->value('content'));
        Http::assertSentCount(1);
    }

    public function test_ocr_cannot_publish_after_access_revocation(): void
    {
        $workspace = $this->workspace();
        $upload = $this->withHeader('Idempotency-Key', 'ocr-image-upload-002')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('sign.png', 32, 16)])->assertCreated()->json();
        $id = $upload['entry']['id'] ?? $upload['id'];
        Http::fake(function () {
            Gate::define('studio.files.process', static fn () => false);

            return Http::response(['responses' => [['fullTextAnnotation' => ['text' => 'private text']]]]);
        });
        try {
            app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null);
            $this->fail('Revoked worker must not publish.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_search_content')->count());
    }

    public function test_invalid_images_and_disabled_ocr_never_contact_a_provider(): void
    {
        Http::fake();
        try {
            app(OcrImage::class)->recognize('not an image');
            $this->fail('Invalid image accepted.');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
        }
        config(['media-studio.search.content.ocr.enabled' => false]);
        $this->expectException(HttpException::class);
        app(OcrImage::class)->recognize('not an image');
    }

    public function test_pdf_rasterizes_only_textless_pages_with_real_poppler_and_bounded_ocr(): void
    {
        if (! is_executable('/opt/homebrew/bin/pdftoppm')) {
            $this->markTestSkipped('Real Poppler is required.');
        }
        config(['media-studio.search.content.pdf.info_binary' => '/opt/homebrew/bin/pdfinfo', 'media-studio.search.content.pdf.text_binary' => '/opt/homebrew/bin/pdftotext', 'media-studio.search.content.ocr.pdf_binary' => '/opt/homebrew/bin/pdftoppm', 'media-studio.search.content.ocr.scanned_pdf' => true, 'media-studio.search.content.ocr.max_pdf_pages' => 1]);
        $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>endobj\n3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>endobj\n4 0 obj<< /Length 0 >>stream\n\nendstream\nendobj\n5 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 6 0 R >>endobj\n6 0 obj<< /Length 0 >>stream\n\nendstream\nendobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['fullTextAnnotation' => ['text' => 'Scanned text']]]])]);

        $result = app(PdfTextExtractor::class)->extract($pdf, 12000);
        $this->assertSame('Scanned text', $result['text']);
        $this->assertSame(1, $result['ocrPages']);
        $this->assertSame('partial', $result['state']);
        Http::assertSentCount(1);
        config(['media-studio.search.content.ocr.max_pdf_pages' => 2]);
        $checks = 0;
        try {
            app(PdfTextExtractor::class)->extract($pdf, 12000, function () use (&$checks): void {
                abort_if(++$checks === 2, 403);
            });
            $this->fail('Revoked guard allowed a second page request.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Http::assertSentCount(2); // One earlier request, one before the revocation.
    }
}
