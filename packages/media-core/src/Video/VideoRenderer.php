<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Video;

use InvalidArgumentException;
use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;

final class VideoRenderer
{
    public function __construct(private readonly ProcessExecutor $processes, private readonly ?string $ffmpeg, private readonly int $maxOutputBytes = 268435456) {}

    private const DEMUXERS = [
        'video/mp4' => 'mov',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'mov',
        'video/webm' => 'matroska',
        'video/x-matroska' => 'matroska',
        'video/x-msvideo' => 'avi',
        'video/avi' => 'avi',
        'video/mpeg' => 'mpeg',
        'video/ogg' => 'ogg',
    ];

    public static function supports(string $mime): bool
    {
        return isset(self::DEMUXERS[$mime]);
    }

    /** @param resource $output */
    public function render(string $source, string $mime, array $recipe, mixed $output): array
    {
        $binary = $this->ffmpeg;
        if (! is_string($binary) || ! str_starts_with($binary, '/') || ! is_executable($binary) || ! isset(self::DEMUXERS[$mime])) {
            throw new RuntimeException('encoder_unavailable');
        }
        $poster = $recipe['role'] === 'poster';
        $encoder = $poster ? $this->posterOptions($recipe) : [
            '-map', '0:a:0?', '-c:v', 'libx264', '-preset', 'veryfast', '-threads', '2',
            '-crf', (string) $recipe['crf'], '-pix_fmt', 'yuv420p', '-c:a', 'aac',
            '-b:a', '128k', '-movflags', '+faststart', '-f', 'mp4',
        ];
        $edge = (int) $recipe['edge'];
        $filter = "scale=w='min(iw,{$edge})':h='min(ih,{$edge})':force_original_aspect_ratio=decrease:force_divisible_by=2";
        $destination = stream_get_meta_data($output)['uri'];
        if ($source === $destination || (realpath($source) !== false && realpath($source) === realpath($destination))) {
            throw new InvalidArgumentException('Video output must not replace its input.');
        }
        $arguments = [
            $binary, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-protocol_whitelist', 'file',
            '-max_alloc', '67108864', '-filter_threads', '1', '-threads', '2', '-f', self::DEMUXERS[$mime],
            '-i', $source, '-map', '0:v:0', '-map_metadata', ($recipe['strip_metadata'] ?? true) ? '-1' : '0', '-map_chapters', '-1', '-sn', '-dn', '-vf', $filter,
            ...$encoder, $destination,
        ];
        $maximum = $poster ? 2 * 1024 * 1024 : max(1048576, min(1073741824, $this->maxOutputBytes));
        $this->processes->writeFile($arguments, $poster ? 12 : 75, $destination, $maximum);
        if ($poster) {
            $dimensions = @getimagesize($destination);
            if ($dimensions === false || $dimensions['mime'] !== $recipe['mime']) {
                throw new RuntimeException('encoding_failed');
            }

            return ['width' => $dimensions[0], 'height' => $dimensions[1]];
        }

        return [];
    }

    private function posterOptions(array $recipe): array
    {
        $quality = max(1, min(100, (int) ($recipe['quality'] ?? 78)));

        return match ($recipe['mime']) {
            'image/webp' => ['-an', '-frames:v', '1', '-c:v', 'libwebp', '-threads', '2',
                '-quality', (string) $quality, '-f', 'webp'],
            'image/avif' => ['-an', '-frames:v', '1', '-c:v', 'libaom-av1', '-threads', '2',
                '-pix_fmt', 'yuv420p', '-still-picture', '1', '-cpu-used', '6', '-b:v', '0',
                '-crf', (string) round((100 - $quality) * 63 / 100), '-f', 'avif'],
            default => throw new RuntimeException('encoder_unavailable'),
        };
    }
}
