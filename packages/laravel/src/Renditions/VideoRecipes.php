<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use InvalidArgumentException;

final class VideoRecipes
{
    public static function recipes(): array
    {
        $settings = config('media-studio.processing.video', []);
        $connection = config('media-studio.processing.connection') ?: config('queue.default');
        if (($settings['enabled'] ?? false) !== true || config('queue.connections.'.$connection.'.driver', 'sync') === 'sync') {
            return [];
        }

        $recipes = [];
        if ($settings['poster'] ?? true) {
            $format = $settings['poster_format'] ?? 'webp';
            if (! in_array($format, ['webp', 'avif'], true)) {
                throw new InvalidArgumentException('Video poster format must be webp or avif.');
            }
            $recipes[config('media-studio.processing.thumbnails.preset', 'thumbnail-v1')] = [
                'role' => 'poster',
                'edge' => max(64, min(1200, (int) ($settings['poster_edge'] ?? 480))),
                'quality' => max(1, min(100, (int) ($settings['poster_quality'] ?? 78))),
                'mime' => 'image/'.$format, 'strip_metadata' => $settings['strip_metadata'] ?? true,
            ];
        }
        if ($settings['compress'] ?? false) {
            $recipes[config('media-studio.processing.preview.preset', 'large-v1')] = [
                'role' => 'playback', 'edge' => max(320, min(1920, (int) ($settings['max_edge'] ?? 1280))),
                'crf' => max(16, min(35, (int) ($settings['crf'] ?? 26))), 'mime' => 'video/mp4',
                'strip_metadata' => $settings['strip_metadata'] ?? true,
            ];
        }

        return $recipes;
    }
}
