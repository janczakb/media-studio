<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Images;

final class ImageGeometry
{
    public static function forPreset(int $sourceWidth, int $sourceHeight, ImagePreset $preset): array
    {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new \InvalidArgumentException('Source dimensions must be positive.');
        }
        $recipe = $preset->recipe;
        $width = $recipe['width'];
        $height = $recipe['height'];
        $fit = $recipe['fit'];
        $cropWidth = $sourceWidth;
        $cropHeight = $sourceHeight;
        $cropX = $cropY = 0;
        $targetX = $targetY = 0;
        if ($fit === 'cover') {
            $scale = min($sourceWidth / $width, $sourceHeight / $height);
            $cropWidth = max(1, (int) round($width * $scale));
            $cropHeight = max(1, (int) round($height * $scale));
            $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
            $cropY = (int) round(($sourceHeight - $cropHeight) / 2);
            if ($recipe['position'] === 'left') {
                $cropX = 0;
            } elseif ($recipe['position'] === 'right') {
                $cropX = $sourceWidth - $cropWidth;
            } elseif ($recipe['position'] === 'top') {
                $cropY = 0;
            } elseif ($recipe['position'] === 'bottom') {
                $cropY = $sourceHeight - $cropHeight;
            }
            $ratio = $recipe['upscale'] ? 1 : min(1, $cropWidth / $width, $cropHeight / $height);
            $width = max(1, (int) round($width * $ratio));
            $height = max(1, (int) round($height * $ratio));
        } elseif ($fit === 'contain' || $fit === 'pad') {
            $ratio = min($width / $sourceWidth, $height / $sourceHeight, $recipe['upscale'] ? PHP_FLOAT_MAX : 1);
            $drawWidth = max(1, (int) round($sourceWidth * $ratio));
            $drawHeight = max(1, (int) round($sourceHeight * $ratio));
            if ($fit === 'contain') {
                $width = $drawWidth;
                $height = $drawHeight;
            } else {
                $targetX = (int) floor(($width - $drawWidth) / 2);
                $targetY = (int) floor(($height - $drawHeight) / 2);
            }
        } elseif (! $recipe['upscale']) {
            $width = min($width, $sourceWidth);
            $height = min($height, $sourceHeight);
        }

        return [$width, $height, $targetX, $targetY, $cropX, $cropY, $drawWidth ?? $width, $drawHeight ?? $height, $cropWidth, $cropHeight];
    }
}
