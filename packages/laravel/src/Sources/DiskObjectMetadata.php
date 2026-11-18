<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;

final class DiskObjectMetadata
{
    /** @return array{mime: string, size: int, modified: int} */
    public static function read(FilesystemAdapter $disk, string $path): array
    {
        if ($disk instanceof AwsS3V3Adapter) {
            $result = $disk->getClient()->headObject(['Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($path)]);
            abort_unless(isset($result['ContentLength']) && $result['LastModified'] instanceof \DateTimeInterface, 422, 'Source metadata is incomplete.');

            return ['mime' => (string) ($result['ContentType'] ?? 'application/octet-stream'), 'size' => (int) $result['ContentLength'], 'modified' => $result['LastModified']->getTimestamp()];
        }
        abort_unless($disk->fileExists($path), 404, 'Source object unavailable.');

        return ['mime' => $disk->mimeType($path) ?: 'application/octet-stream', 'size' => $disk->size($path), 'modified' => $disk->lastModified($path)];
    }
}
