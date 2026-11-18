<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Spatie;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use UseFlex\MediaStudio\Identity\Principal;

final readonly class SourceMount
{
    public function __construct(public string $key, public array $config) {}

    public static function find(string $key): ?self
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $key)) {
            return null;
        }

        $config = config('media-studio.integrations.spatie.mounts.'.$key);
        if (! is_array($config) || ($config['enabled'] ?? true) !== true) {
            return null;
        }

        return new self($key, $config);
    }

    public function query(object $workspace, Principal $principal): Builder
    {
        $model = $this->config['media_model'] ?? config('media-library.media_model', 'Spatie\\MediaLibrary\\MediaCollections\\Models\\Media');
        $scope = $this->config['scope'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new LogicException('Configure an installed Spatie Media model for this mount.');
        }
        if (is_string($scope) && class_exists($scope)) {
            $scope = app($scope);
        }
        if (! $scope instanceof Closure && ! is_callable($scope)) {
            throw new LogicException('Each Spatie mount requires a server-side access scope.');
        }
        $query = (new $model)->newQuery();
        $key = $query->getModel()->getQualifiedKeyName();
        $scoped = $scope(clone $query, $workspace, $principal);
        if (! $scoped instanceof Builder || $scoped->getModel()::class !== $model) {
            throw new LogicException('The Spatie scope must return its media query.');
        }

        // Keep host OR predicates isolated from Studio's cursor and media-key constraints.
        return $query->whereIn($key, $scoped->reorder()->select($key)->toBase());
    }

    public function identity(Model $media): string
    {
        return hash('sha256', json_encode([
            $media::class, $media->getConnection()->getName(), $media->getTable(), (string) $media->getKey(),
            $media->getAttributes()['uuid'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function fingerprint(Model $media, string $path): string
    {
        return hash('sha256', json_encode([
            $this->identity($media), $media->getAttribute('disk'), $path, $media->getAttribute('mime_type'),
            (int) $media->getAttribute('size'), $media->getRawOriginal('updated_at'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{disk: string, path: string, mime: string}|null */
    public function thumbnail(Model $media): ?array
    {
        return $this->imageConversion($media, 'thumbnail_conversion');
    }

    /** @return array{disk: string, path: string, mime: string}|null */
    public function preview(Model $media): ?array
    {
        return $this->imageConversion($media, 'preview_conversion');
    }

    /** @return array{disk: string, path: string, mime: string}|null */
    private function imageConversion(Model $media, string $option): ?array
    {
        $conversion = $this->config[$option] ?? null;
        if (! is_string($conversion) || $conversion === '' || ! method_exists($media, 'hasGeneratedConversion') || ! $media->hasGeneratedConversion($conversion)) {
            return null;
        }

        try {
            $path = $media->getPathRelativeToRoot($conversion);
        } catch (InvalidConversion) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };

        return $mime === null ? null : ['disk' => (string) ($media->getAttribute('conversions_disk') ?: $media->getAttribute('disk')), 'path' => $path, 'mime' => $mime];
    }
}
