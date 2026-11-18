<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Search\ExtractTextContent;
use UseFlex\MediaStudio\Search\PdfTextExtractor;

final class PdfSearchContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable('/opt/homebrew/bin/pdftotext') || ! is_executable('/opt/homebrew/bin/pdfinfo')) {
            $this->markTestSkipped('Real Poppler executables are required for PDF acceptance.');
        }
        config(['media-studio.search.content.pdf.info_binary' => '/opt/homebrew/bin/pdfinfo', 'media-studio.search.content.pdf.text_binary' => '/opt/homebrew/bin/pdftotext']);
    }

    private function pdf(array $texts): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => (4 + $i * 2).' 0 R', array_keys($texts))).'] /Count '.count($texts).' >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        foreach ($texts as $index => $text) {
            $page = 4 + $index * 2;
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($page + 1).' 0 R >>';
            $stream = 'BT /F1 12 Tf 20 250 Td ('.$text.') Tj ET';
            $objects[$page + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }

    public function test_real_poppler_extracts_text_without_rendering_and_reports_page_limits(): void
    {
        $source = $this->pdf(['Hotel brochure', 'Ocean view']);
        $result = app(PdfTextExtractor::class)->extract($source, 12000);
        $this->assertSame('Hotel brochure Ocean view', $result['text']);
        $this->assertSame('ready', $result['state']);
        $this->assertSame(2, $result['pages']);
        config(['media-studio.search.content.pdf.max_pages' => 1]);
        $result = app(PdfTextExtractor::class)->extract($source, 12000);
        $this->assertSame('Hotel brochure', $result['text']);
        $this->assertSame('partial', $result['state']);
        $this->assertSame(1, $result['processedPages']);
    }

    public function test_no_text_is_not_falsely_reported_as_successful_ocr_and_mixed_coverage_is_partial(): void
    {
        $result = app(PdfTextExtractor::class)->extract($this->pdf(['']), 12000);
        $this->assertSame('no_text', $result['state']);
        $this->assertSame('', $result['text']);
        $result = app(PdfTextExtractor::class)->extract($this->pdf(['Readable', '']), 12000);
        $this->assertSame('partial', $result['state']);
        $this->assertSame(1, $result['emptyTextPages']);
    }

    public function test_uploaded_pdf_publishes_version_bound_text_through_the_real_extraction_service(): void
    {
        foreach (glob(__DIR__.'/../packages/laravel/database/search-migrations/*.php') as $path) {
            (require $path)->up();
        }
        config(['media-studio.search.content.enabled' => true, 'media-studio.search.content.pdf.enabled' => true]);
        $workspace = $this->workspace();
        $upload = $this->withHeader('Idempotency-Key', 'pdf-search-content-test-001')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent('brochure.pdf', $this->pdf(['Hotel brochure']))])->assertCreated()->json();
        $id = $upload['entry']['id'] ?? $upload['id'];
        $this->assertSame('ready', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $row = DB::table('fms_search_content')->first();
        $this->assertSame('Hotel brochure', $row->content);
        $this->assertSame(DB::table('fms_entries')->where('public_id', $id)->value('current_version_id'), $row->version_id);
    }

    public function test_character_limit_is_explicit_and_corrupt_pdf_is_rejected(): void
    {
        $this->assertSame('partial', app(PdfTextExtractor::class)->extract($this->pdf(['Long paragraph']), 3)['state']);
        $this->expectException(\RuntimeException::class);
        app(PdfTextExtractor::class)->extract('%PDF-invalid', 12000);
    }
}
