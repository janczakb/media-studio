<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use InvalidArgumentException;

/** An existing provider-owned object. URLs and permission grants are deliberately absent. */
final readonly class ExternalVariant
{
    public function __construct(
        public string $name,
        public string $disk,
        public string $objectKey,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $sha256 = null,
    ) {
        if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $name)
            || $disk === '' || strlen($disk) > 255 || preg_match('/[\x00-\x1f\x7f]/', $disk)
            || $objectKey === '' || strlen($objectKey) > 1024 || str_starts_with($objectKey, '/')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $objectKey) || str_contains($objectKey, '://')
            || preg_match('/\A[a-zA-Z]:/', $objectKey)
            || preg_match('~(?:\A|/)(?:\.{1,2})?(?:/|\z)~', $objectKey)
            || ! preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~', $mimeType) || strlen($mimeType) > 160
            || $sizeBytes < 0 || $width !== null && ($width < 1 || $width > 4294967295)
            || $height !== null && ($height < 1 || $height > 4294967295)
            || $sha256 !== null && ! preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            throw new InvalidArgumentException('Invalid external media variant.');
        }
    }
}
