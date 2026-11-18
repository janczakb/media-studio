<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

use InvalidArgumentException;
use RuntimeException;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaCore\Process\ProcessExecutor;

final class VipsPdfThumbnailGenerator implements EncodedThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        $preset = new ImagePreset('document', DocumentThumbnails::imageRecipe($profile));
        $binary = config('media-studio.processing.document_thumbnails.vips_binary', 'vips');
        $threads = $profile['vips_concurrency'] ?? 1;
        if (! is_string($binary) || $binary === '' || str_contains($binary, "\0")
            || ! is_int($threads) || $threads < 1 || $threads > 8
            || strpbrk($sourcePath, '[]') !== false) {
            throw new InvalidArgumentException('Invalid libvips thumbnail configuration.');
        }
        $directory = sys_get_temp_dir().'/fms-vips-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('document_temp_failed');
        }
        $path = $directory.'/thumbnail.'.$preset->recipe['format'];
        $format = $preset->recipe['format'];
        $saver = $format === 'webp'
            ? 'Q='.$profile['quality'].',effort=2,strip,smart_subsample'
            : 'compression=av1,Q='.$profile['quality'].',effort=2,strip';
        try {
            app(ProcessExecutor::class)->writeFile([
                $binary,
                'thumbnail',
                '--vips-concurrency='.$threads,
                '--vips-cache-max=0',
                '--vips-cache-max-memory=0',
                '--vips-cache-max-files=0',
                $sourcePath.'[page=0,n=1]',
                $path.'['.$saver.']',
                (string) $profile['width'],
                '--height='.(string) $profile['height'],
                '--size=down',
            ], $profile['timeout'], $path, $profile['max_raster_bytes'], 'document_vips_render_failed');
            $output = tmpfile();
            $input = fopen($path, 'rb');
            if (! is_resource($output) || ! is_resource($input)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('document_temp_failed');
            }
            try {
                $copied = stream_copy_to_stream($input, $output, $profile['max_raster_bytes'] + 1);
                if ($copied === false || $copied < 1 || $copied > $profile['max_raster_bytes']) {
                    throw new RuntimeException('document_output_too_large');
                }
                rewind($output);
            } catch (\Throwable $error) {
                fclose($output);
                throw $error;
            } finally {
                fclose($input);
            }

            return $output;
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
