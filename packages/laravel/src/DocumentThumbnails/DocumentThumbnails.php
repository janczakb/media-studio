<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UseFlex\MediaCore\Images\ImagePreset;

final class DocumentThumbnails
{
    public function recipe(object $entry, ?string $modelClass = null): ?array
    {
        if (! config('media-studio.processing.document_thumbnails.enabled', false)) {
            return null;
        }
        $formats = config('media-studio.processing.document_thumbnails.formats', []);
        $settings = $formats[$entry->mime_type] ?? null;
        if (! is_array($settings)) {
            return null;
        }
        $defaults = config('media-studio.processing.document_thumbnails', []);
        if ($modelClass === null && isset($entry->current_version_id)) {
            $modelClass = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->value('rendition_model');
        }
        $overrides = $modelClass === null ? [] : ($defaults['model_profiles'][$modelClass] ?? []);
        $settings = array_replace($settings, $overrides);
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }
        $mode = $settings['execution'] ?? $defaults['execution'] ?? 'command';
        $generator = $settings['generator'] ?? null;
        if ($generator === PdfThumbnailGenerator::class) {
            $backend = $settings['pdf_backend'] ?? $defaults['pdf_backend'] ?? 'poppler';
            $generator = match ($backend) {
                'poppler' => PdfThumbnailGenerator::class,
                'vips' => VipsPdfThumbnailGenerator::class,
                default => throw new InvalidArgumentException('Invalid PDF thumbnail backend.'),
            };
        }
        if (! in_array($mode, ['command', 'queue', 'inline'], true)
            || ! is_string($generator) || ! is_subclass_of($generator, ThumbnailGenerator::class)) {
            throw new InvalidArgumentException('Invalid document thumbnail generator or execution mode.');
        }
        if ($mode === 'inline' && ! ($settings['allow_inline'] ?? false)) {
            throw new InvalidArgumentException('This document generator is not enabled for inline execution.');
        }
        $recipe = [
            'width' => $settings['edge'] ?? $defaults['edge'] ?? 480,
            'height' => $settings['edge'] ?? $defaults['edge'] ?? 480,
            'format' => $settings['format'] ?? $defaults['format'] ?? 'webp',
            'quality' => $settings['quality'] ?? $defaults['quality'] ?? 78,
            'fit' => 'contain', 'upscale' => false, 'strip_metadata' => true,
            'background' => '#ffffff', 'position' => 'center',
            'document_generator' => $generator, 'document_execution' => $mode,
            'source_extension' => strtolower(pathinfo($entry->name ?? '', PATHINFO_EXTENSION)),
            'generator_revision' => (string) ($settings['revision'] ?? '1'),
            'max_source_bytes' => $settings['max_source_bytes'] ?? $defaults['max_source_bytes'] ?? 26214400,
            'max_raster_bytes' => $defaults['max_raster_bytes'] ?? 8388608,
            'timeout' => $settings['timeout'] ?? $defaults['timeout'] ?? 30,
        ];
        if ($generator === VipsPdfThumbnailGenerator::class) {
            $threads = $settings['vips_concurrency'] ?? $defaults['vips_concurrency'] ?? 1;
            if (! is_int($threads) || $threads < 1 || $threads > 8) {
                throw new InvalidArgumentException('Invalid libvips thumbnail concurrency.');
            }
            $recipe['vips_concurrency'] = $threads;
        }
        foreach (['max_source_bytes' => 268435456, 'max_raster_bytes' => 33554432, 'timeout' => 90] as $key => $max) {
            if (! is_int($recipe[$key]) || $recipe[$key] < 1 || $recipe[$key] > $max) {
                throw new InvalidArgumentException('Invalid document thumbnail resource limit.');
            }
        }
        new ImagePreset('document', self::imageRecipe($recipe));

        return ($entry->size_bytes ?? PHP_INT_MAX) <= $recipe['max_source_bytes'] ? $recipe : null;
    }

    public static function imageRecipe(array $recipe): array
    {
        return array_diff_key($recipe, array_flip(['vips_concurrency', 'source_extension', 'document_generator', 'document_execution', 'generator_revision', 'max_source_bytes', 'max_raster_bytes', 'timeout']));
    }
}
