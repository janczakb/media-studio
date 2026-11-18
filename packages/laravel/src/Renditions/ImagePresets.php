<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UseFlex\MediaCore\Images\ImagePreset;

/** Application configuration is resolved once, outside the image engine. */
final class ImagePresets
{
    public static function configured(?Model $model = null): array
    {
        return self::forModel($model === null ? null : $model::class);
    }

    public static function forModel(?string $modelClass): array
    {
        $config = config('media-studio.processing.renditions', [
            'thumbnail-v1' => ['format' => 'avif', 'quality' => 40],
            'medium-v1' => ['width' => 1000, 'height' => 1000, 'format' => 'avif', 'quality' => 40],
            'large-v1' => ['width' => 1900, 'height' => 1900, 'format' => 'avif', 'quality' => 50],
        ]);
        $models = config('media-studio.processing.model_renditions', []);
        if ($modelClass !== null && array_key_exists($modelClass, $models)) {
            $config = $models[$modelClass];
        }
        if (! is_array($config) || count($config) > 16) {
            throw new InvalidArgumentException('Configure at most 16 image renditions.');
        }
        $presets = [];
        foreach ($config as $name => $recipe) {
            if (! is_array($recipe)) {
                throw new InvalidArgumentException('A rendition must have an array of settings.');
            }
            if ($name === config('media-studio.processing.thumbnails.preset', 'thumbnail-v1')) {
                $edge = max(64, min(1200, (int) config('media-studio.processing.thumbnails.edge', 400)));
                $recipe += ['width' => $edge, 'height' => $edge];
            }
            // Preserve the recipe hash for WebP profiles that omit this setting.
            $defaultAvifEffort = ($recipe['format'] ?? 'avif') === 'avif' ? 3 : 6;
            $presets[$name] = new ImagePreset((string) $name, $recipe + [
                'fit' => 'contain', 'format' => 'avif', 'quality' => 50, 'avif_effort' => $defaultAvifEffort, 'upscale' => false,
                'background' => '#ffffff', 'position' => 'center', 'disk' => null,
                'strip_metadata' => config('media-studio.processing.native.strip_metadata', true),
            ]);
        }

        return $presets;
    }
}
