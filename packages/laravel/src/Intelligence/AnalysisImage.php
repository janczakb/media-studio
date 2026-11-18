<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Support\Facades\Storage;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImagickImageRenderer;
use UseFlex\MediaCore\Intelligence\AnalysisImage as AnalysisEngine;

final class AnalysisImage
{
    public function prepare(object $version): string
    {
        $maxBytes = (int) config('media-studio.intelligence.max_source_bytes', 10485760);
        $maxPixels = (int) config('media-studio.intelligence.max_source_pixels', 16000000);
        if ($maxBytes < 1 || $maxBytes > 26214400 || $maxPixels < 1 || $maxPixels > 40000000) {
            throw new \LogicException('Invalid analysis source limits.');
        }
        if ($version->size_bytes > $maxBytes || ! in_array($version->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            throw new \RuntimeException('Analysis source exceeds configured limits or is unsupported.');
        }
        $renderer = match (config('media-studio.processing.native.driver', 'auto')) {
            'gd' => app(GdImageRenderer::class),
            'imagick' => app(ImagickImageRenderer::class),
            'auto' => extension_loaded('imagick') ? app(ImagickImageRenderer::class) : app(GdImageRenderer::class),
            default => throw new \LogicException('Unsupported image driver.'),
        };
        $input = Storage::disk($version->disk)->readStream($version->object_key);
        try {
            return (new AnalysisEngine($renderer, $maxBytes, $maxPixels))->prepare($input, (string) $version->sha256);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }
}
