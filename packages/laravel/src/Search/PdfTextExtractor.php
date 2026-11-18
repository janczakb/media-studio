<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Text\PdfTextExtractor as PdfEngine;
use UseFlex\MediaCore\Text\PdfTextProfile;

/** Resolve host configuration and authority; parsing is owned by media-core. */
final class PdfTextExtractor
{
    public function extract(string $input, int $characters, ?callable $beforeOcr = null): array
    {
        $ocr = config('media-studio.search.content.ocr.enabled') === true && config('media-studio.search.content.ocr.scanned_pdf') === true;
        $ocrPages = (int) config('media-studio.search.content.ocr.max_pdf_pages', 5);
        if ($ocr && $ocrPages < 1) {
            throw new \LogicException('Invalid PDF OCR page budget.');
        }
        $profile = new PdfTextProfile(
            config('media-studio.search.content.pdf.info_binary', 'pdfinfo'),
            config('media-studio.search.content.pdf.text_binary', 'pdftotext'),
            (int) config('media-studio.search.content.pdf.max_pages', 25),
            (int) config('media-studio.search.content.pdf.timeout_seconds', 10),
            (int) config('media-studio.search.content.ocr.max_seconds', 90),
            $ocr ? $ocrPages : 0,
        );

        return (new PdfEngine(app(ProcessExecutor::class), $profile))->extract($input, $characters, $ocr ? static function (string $path, int $page) use ($beforeOcr): string {
            if ($beforeOcr !== null) {
                $beforeOcr();
            }

            return app(PdfPageOcr::class)->extract($path, $page);
        } : null);
    }
}
