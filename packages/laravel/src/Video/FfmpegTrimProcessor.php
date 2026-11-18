<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Video;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Video\FfmpegTrimEngine;

final class FfmpegTrimProcessor implements VideoTrimProcessor
{
    private function binary(string $name): string
    {
        $path = config('media-studio.video_editing.'.$name);
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_executable($path)) {
            throw new RuntimeException('processor_unavailable');
        }

        return $path;
    }

    public function available(): bool
    {
        try {
            $ffmpeg = $this->binary('ffmpeg');
            $probe = $this->binary('ffprobe');

            return (bool) Cache::store(config('media-studio.video_editing.cache_store'))->remember(
                'fms.video-capabilities.'.hash('sha256', php_uname('n').$ffmpeg.$probe.filemtime($ffmpeg).filemtime($probe)),
                300,
                function () use ($ffmpeg, $probe): bool {
                    $processes = app(ProcessExecutor::class);
                    $encoders = $processes->capture([$ffmpeg, '-hide_banner', '-encoders'], 5, 262144);
                    $processes->capture([$probe, '-version'], 5, 16384);

                    return str_contains($encoders, 'libx264') && preg_match('/\baac\b/', $encoders) === 1;
                },
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function engine(): FfmpegTrimEngine
    {
        return new FfmpegTrimEngine(
            app(ProcessExecutor::class), $this->binary('ffmpeg'), $this->binary('ffprobe'),
            (int) config('media-studio.video_editing.max_pixels', 40000000),
            (int) config('media-studio.video_editing.max_output_bytes', 268435456),
            (int) config('media-studio.video_editing.timeout', 60),
            (int) config('media-studio.video_editing.crf', 23),
        );
    }

    public function inspect(string $localFile): array
    {
        return $this->engine()->inspect($localFile);
    }

    public function trim(string $source, string $destination, float $start, float $end): void
    {
        $this->engine()->trim($source, $destination, $start, $end);
    }
}
