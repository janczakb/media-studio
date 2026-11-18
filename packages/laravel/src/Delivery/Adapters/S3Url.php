<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery\Adapters;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use UseFlex\MediaStudio\Delivery\DeliveryAdapter;
use UseFlex\MediaStudio\Delivery\DeliveryResource;

final class S3Url implements DeliveryAdapter
{
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string
    {
        $disk = Storage::disk($resource->disk);
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new LogicException('S3 media delivery requires a native Laravel S3 disk.');
        }
        $inline = preg_match('~\A(?:image/(?:jpeg|png|webp|avif|gif)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf)\z~', $resource->mime) === 1 && ! $resource->download;
        $filename = str_replace(['/', '\\'], '_', $resource->name);
        $fallback = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?: 'media';

        return $disk->temporaryUrl($resource->key, now()->setTimestamp($expiresAt), [
            'ResponseContentType' => $resource->mime,
            'ResponseContentDisposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $filename, $fallback),
            'ResponseCacheControl' => 'private, no-store',
        ]);
    }
}
