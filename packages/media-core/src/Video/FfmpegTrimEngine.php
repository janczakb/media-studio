<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Video;

use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;

final readonly class FfmpegTrimEngine
{
    public function __construct(private ProcessExecutor $processes, private string $ffmpeg, private string $ffprobe, private int $maxPixels = 40000000, private int $maxOutputBytes = 268435456, private int $timeout = 60, private int $crf = 23)
    {
        if ($maxPixels < 1 || $maxOutputBytes < 1 || $timeout < 1 || $crf < 0 || $crf > 51) {
            throw new \InvalidArgumentException('Invalid video processing limits.');
        }
    }

    public function inspect(string $localFile): array
    {
        $info = json_decode($this->processes->capture([$this->ffprobe, '-v', 'error', '-protocol_whitelist', 'file', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $localFile], 10, 65536), true, 16, JSON_THROW_ON_ERROR);
        $duration = (float) ($info['format']['duration'] ?? 0);
        $width = (int) ($info['streams'][0]['width'] ?? 0);
        $height = (int) ($info['streams'][0]['height'] ?? 0);
        if (! is_finite($duration) || $duration <= 0 || $width < 1 || $height < 1 || $width * $height > $this->maxPixels) {
            throw new RuntimeException('unsupported_video');
        }

        return compact('duration', 'width', 'height');
    }

    public function trim(string $source, string $destination, float $start, float $end): void
    {
        if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end <= $start || $source === $destination || (realpath($source) !== false && realpath($source) === realpath($destination))) {
            throw new \InvalidArgumentException('Invalid video range or destination.');
        }
        $arguments = [
            $this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-protocol_whitelist', 'file',
            '-max_alloc', '67108864', '-threads', '2', '-filter_threads', '1', '-i', $source,
            '-ss', sprintf('%.6F', $start), '-t', sprintf('%.6F', $end - $start), '-map', '0:v:0', '-map', '0:a:0?',
            '-map_metadata', '-1', '-map_chapters', '-1', '-sn', '-dn', '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast',
            '-crf', (string) $this->crf, '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-f', 'mp4', $destination,
        ];
        $this->processes->writeFile($arguments, $this->timeout, $destination, $this->maxOutputBytes, 'processing_failed');
    }
}
