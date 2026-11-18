<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Symfony\Component\Mime\MimeTypes;
use UseFlex\MediaStudio\Library\FileCategories;

final class AcceptedFiles
{
    public static function configured(): array
    {
        return array_values(array_intersect(FileCategories::extensions(), config(
            'media-studio.upload.extensions',
            FileCategories::extensions(),
        )));
    }

    public static function extensions(object $workspace): array
    {
        $stored = json_decode($workspace->studio_settings ?? '{}', true);

        return array_values(array_intersect(self::configured(), $stored['extensions'] ?? self::configured()));
    }

    public static function assertName(object $workspace, string $name): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, self::extensions($workspace), true), 422, 'This file extension is not accepted.');
    }

    public static function assertAllowed(object $workspace, string $name, string $mime): void
    {
        self::assertName($workspace, $name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $explicit = config('media-studio.upload.mime_types');
        if (is_array($explicit)) {
            abort_unless(in_array($mime, $explicit, true), 422, 'This file type is not accepted.');
        }

        $expected = MimeTypes::getDefault()->getMimeTypes($extension);
        // libmagic identifies several text formats as plain text and older
        // Office documents as generic OLE. Neither is served inline.
        $text = array_merge(FileCategories::EXTENSIONS['code'], ['txt', 'md', 'csv', 'tsv', 'rtf']);
        $aliases = match ($mime) {
            'text/plain' => $text,
            'application/x-ole-storage', 'application/CDFV2' => ['doc', 'xls', 'ppt'],
            'application/ogg' => ['ogg', 'opus', 'ogv'],
            'application/zip' => ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub', 'pages', 'numbers', 'key'],
            default => [],
        };
        abort_unless(in_array($mime, $expected, true) || in_array($extension, $aliases, true), 422, 'The file content does not match its extension.');
    }
}
