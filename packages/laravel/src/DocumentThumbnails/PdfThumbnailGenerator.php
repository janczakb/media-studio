<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Text\PdfPageRasterizer;

final class PdfThumbnailGenerator implements ThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        $binary = config('media-studio.processing.document_thumbnails.pdf_binary', 'pdftoppm');
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('document_renderer_unavailable');
        }
        $output = tmpfile();
        if (! is_resource($output)) {
            throw new RuntimeException('document_temp_failed');
        }
        try {
            $rasterizer = new PdfPageRasterizer(app(ProcessExecutor::class), $binary, $profile['width'], $profile['timeout']);
            $png = $rasterizer->png($sourcePath, 1, $profile['max_raster_bytes']);
            if ($png === '' || fwrite($output, $png) !== strlen($png)) {
                throw new RuntimeException('document_render_failed');
            }
            rewind($output);

            return $output;
        } catch (\Throwable $error) {
            fclose($output);
            throw $error;
        }
    }
}
