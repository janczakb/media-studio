<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Text\PdfPageRasterizer;

final class PdfPageOcr
{
    public function extract(string $source, int $page): string
    {
        $renderer = new PdfPageRasterizer(app(ProcessExecutor::class),
            config('media-studio.search.content.ocr.pdf_binary', 'pdftoppm'),
            (int) config('media-studio.search.content.ocr.pdf_edge', 2048),
            (int) config('media-studio.search.content.ocr.page_timeout_seconds', 15));

        return app(OcrImage::class)->recognize($renderer->jpeg($source, $page));
    }
}
