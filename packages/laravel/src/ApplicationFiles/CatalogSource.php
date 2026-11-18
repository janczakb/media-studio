<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Integrations\Spatie\SourceMount;

final readonly class CatalogSource
{
    public function __construct(public string $key, public array $config, public ?TenantIdentity $tenant) {}

    public static function find(string $key, ?TenantIdentity $tenant): self
    {
        abort_unless(preg_match('/\A[a-z0-9_-]{1,64}\z/', $key), 404);
        $config = config('media-studio.application_files.sources.'.$key);
        abort_unless(is_array($config) && self::enabled($config), 404);
        if (! in_array($config['type'] ?? null, ['disk', 'spatie', 'model'], true) || ! is_string($config['revision'] ?? null) || $config['revision'] === '') {
            throw new LogicException('Each application source requires a type and explicit configuration revision.');
        }

        return new self($key, $config, $tenant);
    }

    public static function enabled(array $config): bool
    {
        return ($config['enabled'] ?? true) === true
            && (($config['type'] ?? null) !== 'disk' || ($config['allow_unregistered'] ?? false) === true);
    }

    public function allows(Principal $principal, ?Authenticatable $actor = null): bool
    {
        $actor ??= app(StudioPermissions::class)->actor($principal);
        abort_unless(Principal::fromUser($actor)->key() === $principal->key(), 403);
        if ($this->config['type'] === 'spatie' && ! app(StudioPermissions::class)->allows($actor, 'application-files.spatie.view')) {
            return false;
        }
        $permission = ApplicationFilePermissions::sourceAbility($this->key, $this->config);
        if ($permission === null || ! Gate::forUser($actor)->allows($permission)) {
            return false;
        }
        $authorize = $this->config['authorize'] ?? null;

        return $authorize === null || (is_callable($authorize) && $authorize($this->tenant, $principal) === true);
    }

    public function tenantScope(): string
    {
        return $this->tenant?->key() ?? hash('sha256', 'fms:application:untenanted');
    }

    public function revision(): string
    {
        $disk = $this->config['type'] === 'disk' ? $this->disk() : null;
        $storage = $disk === null ? [] : (array) config('filesystems.disks.'.$disk);
        $modelStorage = [];
        if ($this->config['type'] === 'model') {
            foreach ($this->modelStores() as $name => $prefix) {
                $modelStorage[$name] = [$prefix, array_intersect_key(config('filesystems.disks.'.$name), array_flip(['driver', 'root', 'bucket', 'endpoint', 'region']))];
            }
            ksort($modelStorage);
        }

        return hash('sha256', json_encode([
            $this->config['type'], $this->config['revision'], $disk,
            $disk === null ? null : $this->prefix(),
            array_intersect_key($storage, array_flip(['driver', 'root', 'bucket', 'endpoint', 'region'])),
            $this->config['media_model'] ?? null, $this->config['thumbnail_conversion'] ?? null,
            $this->config['preview_conversion'] ?? null,
            ...($this->config['type'] === 'model' ? [$this->modelClass(), $modelStorage] : []),
        ], JSON_THROW_ON_ERROR));
    }

    public function disk(): string
    {
        $disk = $this->config['disk'] ?? null;
        if (! is_string($disk) || ! is_array(config('filesystems.disks.'.$disk))) {
            throw new LogicException('Configure an application source disk.');
        }

        return $disk;
    }

    public function prefix(): string
    {
        $prefix = $this->config['prefix'] ?? null;
        $prefix = is_callable($prefix) ? $prefix($this->tenant) : $prefix;
        if (! is_string($prefix)) {
            throw new LogicException('Configure an explicit application source prefix.');
        }

        return $prefix === '' ? '' : $this->path(rtrim($prefix, '/'));
    }

    public function path(string $path): string
    {
        abort_unless($path !== '' && strlen($path) <= 2048 && mb_check_encoding($path, 'UTF-8') && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $path), 422);
        $parts = explode('/', $path);
        abort_if(count($parts) > 32 || count(array_filter($parts, fn (string $part): bool => $part === '' || $part === '.' || $part === '..' || strlen($part) > 255)) > 0, 422);

        return $path;
    }

    public function relative(string $path): string
    {
        $path = $this->path($path);
        $prefix = $this->prefix();
        abort_unless($prefix === '' || str_starts_with($path, $prefix.'/'), 422, 'Object is outside this application source.');

        return $prefix === '' ? $path : substr($path, strlen($prefix) + 1);
    }

    public function mediaQuery(Principal $principal): Builder
    {
        $model = $this->config['media_model'] ?? config('media-library.media_model', 'Spatie\\MediaLibrary\\MediaCollections\\Models\\Media');

        return $this->scopedQuery($model, $principal);
    }

    /** @return class-string<Model&RegistersApplicationMedia> */
    public function modelClass(): string
    {
        $model = $this->config['model'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class) || ! is_subclass_of($model, RegistersApplicationMedia::class)) {
            throw new LogicException('Configure an Eloquent model implementing RegistersApplicationMedia.');
        }

        return $model;
    }

    public function modelQuery(Principal $principal, Closure $bound): Builder
    {
        return $this->scopedQuery($this->modelClass(), $principal, $bound)->useWritePdo();
    }

    /** @return array<string, list<string>> */
    public function modelStores(): array
    {
        $stores = $this->config['stores'] ?? null;
        if (! is_array($stores) || $stores === [] || count($stores) > 16) {
            throw new LogicException('Configure one to sixteen model media disks and their allowed prefixes.');
        }
        foreach ($stores as $disk => $prefix) {
            $prefix = is_callable($prefix) ? $prefix($this->tenant) : $prefix;
            if (! is_string($disk) || ! is_array(config('filesystems.disks.'.$disk))) {
                throw new LogicException('Configure a valid model media disk and explicit prefix.');
            }
            $prefixes = is_string($prefix) ? [$prefix] : $prefix;
            if (! is_array($prefixes) || ! array_is_list($prefixes) || $prefixes === [] || count($prefixes) > 16) {
                throw new LogicException('Configure one to sixteen allowed prefixes per model media disk.');
            }
            foreach ($prefixes as $index => $value) {
                if (! is_string($value)) {
                    throw new LogicException('Model media prefixes must be strings.');
                }
                $prefixes[$index] = $value === '' ? '' : $this->path(rtrim($value, '/'));
            }
            sort($prefixes, SORT_STRING);
            $stores[$disk] = array_values(array_unique($prefixes));
        }

        return $stores;
    }

    private function scopedQuery(mixed $model, Principal $principal, ?Closure $bound = null): Builder
    {
        $scope = $this->config['scope'] ?? null;
        if (is_string($scope) && class_exists($scope)) {
            $scope = app($scope);
        }
        if (! is_string($model) || ! is_subclass_of($model, Model::class) || ! is_callable($scope)) {
            throw new LogicException('Application model sources require an Eloquent model and a tenant-aware query scope.');
        }
        $query = (new $model)->newQuery();
        $key = $query->getModel()->getQualifiedKeyName();
        $scoped = $scope(clone $query, $this->tenant, $principal);
        if (! $scoped instanceof Builder || $scoped->getModel()::class !== $model) {
            throw new LogicException('The application source scope must return its media query.');
        }

        $scoped->reorder()->select($key);
        if ($bound !== null) {
            // Bound the authorization subquery as well as the outer result.
            $filters = clone $scoped->getQuery();
            $scoped->getQuery()->wheres = [];
            $scoped->getQuery()->setBindings([], 'where')->addNestedWhereQuery($filters);
            $scoped->distinct();
            $bound($scoped, $key);
            $bound($query, $key);
        }

        $authorized = $scoped->toBase();
        if ($bound !== null && $authorized->limit !== null) {
            // MySQL does not accept LIMIT directly inside an IN subquery.
            $authorized = $query->getModel()->getConnection()->query()
                ->fromSub($authorized, 'fms_authorized_owners')->select($query->getModel()->getKeyName());
        }

        return $query->whereIn($key, $authorized);
    }

    public function spatie(?Model $media = null): SourceMount
    {
        $overrides = $media === null ? [] : ($this->config['model_conversions'][(string) $media->getAttribute('model_type')] ?? []);

        return new SourceMount($this->key, [...$this->config, ...array_intersect_key((array) $overrides, array_flip(['thumbnail_conversion', 'preview_conversion']))]);
    }
}
