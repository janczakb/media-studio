<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Video;

use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;

final readonly class VideoFrames
{
    public function __construct(private ProcessExecutor $processes, private string $ffmpeg, private string $ffprobe) {}

    public function inspect(string $source, string $demuxer): array
    {
        return json_decode($this->processes->capture([$this->ffprobe, '-v', 'error', '-protocol_whitelist', 'file', '-f', $demuxer, '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $source], 15, 65536), true, 16, JSON_THROW_ON_ERROR);
    }

    public function frame(string $source, string $demuxer, float $time): string
    {
        if (! is_finite($time) || $time < 0) {
            throw new \InvalidArgumentException('Invalid frame time.');
        }
        $bytes = $this->processes->capture([$this->ffmpeg, '-v', 'error', '-nostdin', '-protocol_whitelist', 'file', '-max_alloc', '67108864', '-threads', '1', '-filter_threads', '1', '-ss', sprintf('%.3f', $time), '-f', $demuxer, '-i', $source, '-map', '0:v:0', '-an', '-sn', '-dn', '-frames:v', '1', '-vf', 'scale=768:768:force_original_aspect_ratio=decrease', '-c:v', 'mjpeg', '-f', 'image2pipe', 'pipe:1'], 15, 1048576);
        $image = @getimagesizefromstring($bytes);
        if ($image === false || ($image['mime'] ?? null) !== 'image/jpeg' || max($image[0], $image[1]) > 768) {
            throw new RuntimeException('Invalid video frame.');
        }

        return $bytes;
    }
}
