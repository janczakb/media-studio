<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Images;

use Imagick;
use ImagickPixel;
use RuntimeException;
use Throwable;

final class ImagickImageRenderer
{
    public function __construct(private readonly int $maxWorkingBytes = 268435456)
    {
        if ($maxWorkingBytes < 1) {
            throw new \InvalidArgumentException('Image working-memory budget must be positive.');
        }
    }

    public function open(string $path, int $maxPixels): Imagick
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('encoder_unavailable');
        }
        $info = @getimagesize($path);
        if ($info === false || min($info[0], $info[1]) < 1 || $maxPixels < $info[0] * $info[1]) {
            throw new RuntimeException('unsupported_dimensions');
        }
        $format = match ($info['mime']) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => throw new RuntimeException('unsupported_image'),
        };
        $this->assertBudget($info[0] * $info[1], 0);
        $image = new Imagick;
        try {
            // Explicit raster coder and first frame avoid arbitrary delegates and sequences.
            $image->readImage($format.':'.$path.'[0]');
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            } elseif (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

            return $image;
        } catch (Throwable $error) {
            $image->clear();
            throw $error;
        }
    }

    /** @param resource $output */
    public function render(Imagick $image, ImagePreset $preset, mixed $output): array
    {
        $recipe = $preset->recipe;
        if (Imagick::queryFormats(strtoupper($recipe['format'])) === []) {
            throw new RuntimeException('encoder_unavailable');
        }
        $sourceWidth = $image->getImageWidth();
        $sourceHeight = $image->getImageHeight();
        [$width, $height, $targetX, $targetY, $cropX, $cropY, $drawWidth, $drawHeight, $cropWidth, $cropHeight] = ImageGeometry::forPreset($sourceWidth, $sourceHeight, $preset);
        $this->assertBudget($sourceWidth * $sourceHeight, $width * $height);
        $scaled = clone $image;
        $canvas = null;
        try {
            if ($cropWidth !== $sourceWidth || $cropHeight !== $sourceHeight) {
                $scaled->cropImage($cropWidth, $cropHeight, $cropX, $cropY);
                $scaled->setImagePage(0, 0, 0, 0);
            }
            if ($drawWidth !== $cropWidth || $drawHeight !== $cropHeight) {
                $scaled->resizeImage($drawWidth, $drawHeight, Imagick::FILTER_LANCZOS, 1);
            }
            if ($recipe['fit'] === 'pad' || $recipe['format'] === 'jpeg') {
                $canvas = new Imagick;
                $canvas->newImage($width, $height, new ImagickPixel($recipe['background']));
                $canvas->compositeImage($scaled, Imagick::COMPOSITE_OVER, $targetX, $targetY);
            }
            $target = $canvas ?? $scaled;
            $target->setImageFormat($recipe['format']);
            $target->setImageCompressionQuality($recipe['quality']);
            if ($recipe['format'] === 'avif') {
                $target->setOption('heic:speed', (string) (9 - ($recipe['avif_effort'] ?? 6)));
            }
            if ($recipe['strip_metadata'] ?? true) {
                $target->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                $target->stripImage();
            } elseif ($canvas !== null) {
                // A padding canvas starts empty. Copy profiles and properties
                // explicitly instead of losing metadata on this fit mode.
                foreach ($scaled->getImageProfiles('*', true) as $name => $profile) {
                    $target->profileImage($name, $profile);
                }
                foreach ($scaled->getImageProperties('*', true) as $name => $value) {
                    $target->setImageProperty($name, $value);
                }
            }
            $target->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            $target->setImagePage(0, 0, 0, 0);
            // A stream has no format-bearing filename and can inherit the source coder.
            if (! $target->writeImageFile($output, $recipe['format']) || (fstat($output)['size'] ?? 0) < 1) {
                throw new RuntimeException('encoding_failed');
            }

            return ['width' => $width, 'height' => $height];
        } finally {
            $canvas?->clear();
            $scaled->clear();
        }
    }

    private function assertBudget(int $sourcePixels, int $targetPixels): void
    {
        $bytes = 16 * 1024 * 1024 + $sourcePixels * 16 + $targetPixels * 16;
        if ($bytes > $this->maxWorkingBytes) {
            throw new RuntimeException('image_memory_limit');
        }
    }
}
