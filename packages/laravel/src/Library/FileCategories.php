<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

final class FileCategories
{
    public const EXTENSIONS = [
        'code' => ['css', 'html', 'htm', 'js', 'jsx', 'ts', 'tsx', 'json', 'xml', 'yaml', 'yml', 'php', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'sh', 'sql', 'vue', 'svelte', 'scss', 'sass', 'less'],
        'document' => ['pdf', 'txt', 'md', 'rtf', 'doc', 'docx', 'odt', 'pages', 'epub'],
        'spreadsheet' => ['csv', 'tsv', 'xls', 'xlsx', 'ods', 'numbers'],
        'presentation' => ['ppt', 'pptx', 'odp', 'key'],
        'image' => ['avif', 'bmp', 'gif', 'heic', 'heif', 'ico', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp'],
        'video' => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'mpeg', 'mpg', 'ogv'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'aiff', 'opus'],
        'archive' => ['zip', 'gz', 'tar', 'bz2', 'xz', '7z', 'rar'],
        'font' => ['woff', 'woff2', 'ttf', 'otf', 'eot'],
    ];

    public static function sqlExpression(string $extension = 'browse_extension'): string
    {
        $expression = "CASE WHEN kind = 'folder' THEN '' ";
        foreach (self::EXTENSIONS as $category => $extensions) {
            $values = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $extensions));
            $expression .= "WHEN ($extension) IN ($values) THEN '$category' ";
        }

        return $expression."WHEN media_type IN ('image', 'video', 'audio') THEN media_type WHEN mime_type = 'application/pdf' OR media_type = 'text' THEN 'document' ELSE 'other' END";
    }

    public static function extensions(): array
    {
        $extensions = config('media-studio.browse.extensions', array_merge(...array_values(self::EXTENSIONS)));
        if (! is_array($extensions) || count($extensions) > 512) {
            throw new \LogicException('Configure at most 512 extension choices.');
        }
        $extensions = array_values(array_unique(array_filter((array) $extensions, static fn ($value): bool => is_string($value) && preg_match('/\A[a-z0-9][a-z0-9+_-]{0,31}\z/', $value))));
        sort($extensions, SORT_STRING);

        return $extensions;
    }
}
