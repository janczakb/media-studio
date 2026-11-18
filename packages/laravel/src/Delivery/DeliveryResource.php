<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

use InvalidArgumentException;

/** Resolved only after the caller's workspace, source and version authorization. */
final readonly class DeliveryResource
{
    public const ROLES = ['thumbnail', 'preview', 'original', 'video', 'archive', 'rendition'];

    public function __construct(
        public string $disk,
        public string $key,
        public string $mime,
        public string $name,
        public string $workspace,
        public string $version,
        public string $role,
        public ?string $preset = null,
        public bool $download = false,
        public ?int $expiresAt = null,
    ) {
        if ($disk === '' || $workspace === '' || $version === '' || ! in_array($role, self::ROLES, true)
            || $key === '' || strlen($key) > 2048 || str_contains($key, '\\') || preg_match('/[\x00-\x1f\x7f]/', $key)
            || array_intersect(explode('/', $key), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('Invalid media delivery resource.');
        }
    }

    public static function fromRecord(object $record, string $workspace, string $version, string $role, ?string $preset = null, bool $download = false, ?int $expiresAt = null): self
    {
        return new self($record->disk, $record->object_key, $record->mime_type, $record->name, $workspace, $version, $role, $preset, $download, $expiresAt);
    }

    public function cacheKey(): string
    {
        return hash('sha256', json_encode([$this->workspace, $this->disk, $this->key, $this->version, $this->preset, $this->mime, $this->download, $this->download ? $this->name : null], JSON_THROW_ON_ERROR));
    }
}
