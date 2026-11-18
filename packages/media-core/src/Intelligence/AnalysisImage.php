<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Intelligence;

use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaCore\Images\ImagickImageRenderer;

/** Ephemeral provider-compatible derivative: never persists or replaces delivery conversions. */
final class AnalysisImage
{
    public function __construct(private readonly GdImageRenderer|ImagickImageRenderer $renderer, private readonly int $maxBytes = 10485760, private readonly int $maxPixels = 16000000) {}

    /** @param resource $input The caller owns this stream; temporary outputs are discarded. */
    public function prepare(mixed $input, string $sha256): string
    {
        $maxBytes = $this->maxBytes;
        $maxPixels = $this->maxPixels;
        if (! is_resource($input) || $maxBytes < 1 || $maxBytes > 26214400 || $maxPixels < 1 || $maxPixels > 40000000) {
            throw new \InvalidArgumentException('Invalid analysis source limits or stream.');
        }
        $source = tmpfile();
        $output = tmpfile();
        try {
            if (! is_resource($input) || ! is_resource($source) || ! is_resource($output)) {
                throw new \RuntimeException('Analysis staging unavailable.');
            }
            $copied = stream_copy_to_stream($input, $source, $maxBytes + 1);
            $path = stream_get_meta_data($source)['uri'];
            if ($copied === false || $copied > $maxBytes || (! preg_match('/\A[a-f0-9]{64}\z/', $sha256) || ! hash_equals($sha256, hash_file('sha256', $path)))) {
                throw new \RuntimeException('Analysis source changed or exceeds limits.');
            }
            $renderer = $this->renderer;
            $image = $renderer->open($path, $maxPixels);
            try {
                $preset = new ImagePreset('ai-analysis-v1', ['width' => 768, 'height' => 768, 'fit' => 'contain', 'format' => 'webp', 'quality' => 80, 'upscale' => false, 'background' => '#ffffff', 'position' => 'center', 'disk' => null, 'strip_metadata' => true]);
                $renderer->render($image, $preset, $output);
            } finally {
                if ($image instanceof \Imagick) {
                    $image->clear();
                }
                unset($image);
            }
            rewind($output);
            $bytes = stream_get_contents($output, 1048577);
            if (! is_string($bytes) || strlen($bytes) > 1048576) {
                throw new \RuntimeException('Analysis derivative exceeds byte limit.');
            }

            // Delivery presets intentionally allow only WebP/AVIF. Transcode the
            // already small stripped WebP solely for provider input, never storage.
            if (extension_loaded('imagick')) {
                $small = new \Imagick;
                try {
                    $small->readImageBlob($bytes);
                    $small->setImageFormat('jpeg');
                    $small->setImageCompressionQuality(80);
                    $small->stripImage();
                    $bytes = $small->getImageBlob();
                } finally {
                    $small->clear();
                }
            } else {
                $small = imagecreatefromstring($bytes);
                if (! $small instanceof \GdImage) {
                    throw new \RuntimeException('Analysis transcode failed.');
                }
                ob_start();
                try {
                    imagejpeg($small, null, 80);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                    unset($small);
                }
            }
            if (! is_string($bytes) || strlen($bytes) > 1048576) {
                throw new \RuntimeException('Analysis transcode exceeds byte limit.');
            }

            return $bytes;
        } finally {
            foreach ([$source, $output] as $resource) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        }
    }
}
