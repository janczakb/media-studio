<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Images;

use GdImage;
use RuntimeException;

final class GdImageRenderer
{
    public function __construct(private readonly int $maxWorkingBytes = 268435456)
    {
        if ($maxWorkingBytes < 1) {
            throw new \InvalidArgumentException('Image working-memory budget must be positive.');
        }
    }

    public static function supports(string $format): bool
    {
        if (! extension_loaded('gd')) {
            return false;
        }
        $flag = match ($format) {
            'jpeg' => IMG_JPG,
            'png' => IMG_PNG,
            'webp' => IMG_WEBP,
            'avif' => defined('IMG_AVIF') ? IMG_AVIF : 0,
            default => 0,
        };

        return (imagetypes() & $flag) !== 0;
    }

    public function open(string $path, int $maxPixels): GdImage
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('encoder_unavailable');
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $maxPixels < $info[0] * $info[1]) {
            throw new RuntimeException('unsupported_dimensions');
        }
        $this->memoryBudget($info[0] * $info[1] * 12);
        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };
        if (! $image instanceof GdImage) {
            throw new RuntimeException('unsupported_image');
        }
        if ($info['mime'] !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($path, 'IFD0')['Orientation'] ?? 1);
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('rotation_failed');
        }

        return $rotated;
    }

    private function memoryBudget(int $estimatedBytes): void
    {
        $budget = $this->maxWorkingBytes;
        $reserve = 16 * 1024 * 1024;
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($estimatedBytes + $reserve > $budget || ($limit > 0 && memory_get_usage(true) + $estimatedBytes + $reserve > $limit)) {
            throw new RuntimeException('image_memory_limit');
        }
    }

    /** @param resource $output @return array{width:int,height:int} */
    public function render(GdImage $image, ImagePreset $preset, mixed $output): array
    {
        $recipe = $preset->recipe;
        if (($recipe['strip_metadata'] ?? true) === false) {
            throw new RuntimeException('metadata_requires_imagick');
        }
        if (! self::supports($recipe['format'])) {
            throw new RuntimeException('encoder_unavailable');
        }
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        [$width, $height, $targetX, $targetY, $cropX, $cropY, $drawWidth, $drawHeight, $cropWidth, $cropHeight] = ImageGeometry::forPreset($sourceWidth, $sourceHeight, $preset);
        $this->memoryBudget($sourceWidth * $sourceHeight * 12 + $width * $height * 8);
        $scaled = imagecreatetruecolor($width, $height);
        if (! $scaled instanceof GdImage) {
            throw new RuntimeException('encoding_failed');
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $opaque = $recipe['fit'] === 'pad' || $recipe['format'] === 'jpeg';
        $background = hexdec(substr($recipe['background'], 1));
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled,
            ($background >> 16) & 255, ($background >> 8) & 255, $background & 255, $opaque ? 0 : 127));
        if ($opaque) {
            imagealphablending($scaled, true);
        }
        if (! imagecopyresampled($scaled, $image, $targetX, $targetY, $cropX, $cropY,
            $drawWidth, $drawHeight, $cropWidth, $cropHeight)) {
            throw new RuntimeException('encoding_failed');
        }
        $quality = $recipe['quality'];
        $encoded = match ($recipe['format']) {
            'jpeg' => imagejpeg($scaled, $output, $quality),
            'png' => imagepng($scaled, $output, min(9, (int) round((100 - $quality) * 9 / 100))),
            'webp' => function_exists('imagewebp') ? imagewebp($scaled, $output, $quality) : false,
            'avif' => function_exists('imageavif') ? imageavif($scaled, $output, $quality, 9 - ($recipe['avif_effort'] ?? 6)) : false,
        };
        if (! $encoded || (fstat($output)['size'] ?? 0) < 1) {
            throw new RuntimeException('encoding_failed');
        }

        return ['width' => $width, 'height' => $height];
    }
}
