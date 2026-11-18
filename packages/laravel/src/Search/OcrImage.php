<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use UseFlex\MediaStudio\Intelligence\ProviderHttp;

/** OCR is independent of visual tags; only bounded text is retained. */
final class OcrImage
{
    public function recognize(string $bytes): string
    {
        abort_unless(config('media-studio.search.content.ocr.enabled') === true, 404);
        $size = @getimagesizefromstring($bytes);
        $maximum = (int) config('media-studio.search.content.ocr.max_pixels', 16000000);
        if ($maximum < 1 || $maximum > 40000000 || strlen($bytes) > 4194304 || $size === false || $maximum < $size[0] * $size[1] || ! in_array($size['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            throw new \RuntimeException('OCR image exceeds configured bounds.');
        }
        if ($size['mime'] === 'image/avif') {
            if (extension_loaded('imagick')) {
                $image = new \Imagick;
                try {
                    $image->readImageBlob($bytes);
                    if ($image->getNumberImages() !== 1) {
                        throw new \RuntimeException('Animated OCR inputs are unsupported.');
                    }
                    $image->setImageFormat('jpeg');
                    $image->setImageCompressionQuality(85);
                    $image->stripImage();
                    $bytes = $image->getImageBlob();
                } finally {
                    $image->clear();
                }
            } else {
                $image = @imagecreatefromstring($bytes);
                if (! $image instanceof \GdImage) {
                    throw new \RuntimeException('AVIF OCR decoding unavailable.');
                }
                ob_start();
                try {
                    imagejpeg($image, null, 85);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                    unset($image);
                }
            }
            if (! is_string($bytes) || strlen($bytes) > 4194304) {
                throw new \RuntimeException('OCR transcode exceeds byte limit.');
            }
        }
        $provider = config('media-studio.search.content.ocr.provider', 'google_vision');
        $key = config('media-studio.intelligence.providers.google_vision.key');
        $hints = config('media-studio.search.content.ocr.language_hints', []);
        if ($provider !== 'google_vision' || ! is_string($key) || $key === '' || ! is_array($hints) || count($hints) > 8 || array_filter($hints, static fn ($hint): bool => ! is_string($hint) || ! preg_match('/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?\z/', $hint))) {
            throw new \LogicException('Configure the independent Google Vision OCR profile.');
        }
        $request = ['image' => ['content' => base64_encode($bytes)], 'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']]];
        if ($hints !== []) {
            $request['imageContext'] = ['languageHints' => array_values($hints)];
        }
        // Partial response excludes per-character boxes and unbounded raw geometry.
        $data = app(ProviderHttp::class)->post('https://vision.googleapis.com/v1/images:annotate?fields=responses(fullTextAnnotation/text,error)', ['X-Goog-Api-Key' => $key], ['requests' => [$request]]);
        $result = $data['responses'][0] ?? null;
        if (! is_array($result) || isset($result['error'])) {
            throw new \RuntimeException('OCR provider failed.');
        }
        $text = $result['fullTextAnnotation']['text'] ?? '';
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || strlen($text) > 200000) {
            throw new \UnexpectedValueException('Invalid OCR text response.');
        }

        return $text;
    }
}
