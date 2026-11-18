<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Database\Eloquent\Builder as ModelQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskObjectMetadata;

final class ApplicationCatalog
{
    public function source(Principal $principal, string $key, bool $index = false): CatalogSource
    {
        abort_unless(config('media-studio.application_files.enabled') === true, 404);
        $permissions = app(StudioPermissions::class);
        $actor = $permissions->actor($principal);
        $permissions->authorize($actor, 'application-files.view');
        if ($index) {
            $permissions->authorize($actor, 'application-files.index');
        }
        $source = CatalogSource::find($key, app(WorkspaceAccess::class)->tenant($principal));
        abort_unless($source->allows($principal, $actor), 404);

        return $source;
    }

    public function query(CatalogSource $source): Builder
    {
        return DB::table('fms_application_entries')->where('tenant_scope', $source->tenantScope())
            ->where('source_key', $source->key)->where('source_revision', $source->revision());
    }

    public function indexDisk(Principal $principal, string $key, array $paths): int
    {
        abort_if(count($paths) > 120, 422);
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'disk', 422);
        $count = 0;
        foreach (array_unique($paths) as $path) {
            abort_unless(is_string($path), 422);
            $relative = $source->relative($path);
            $metadata = DiskObjectMetadata::read(Storage::disk($source->disk()), $path);
            $descriptor = ['disk' => $source->disk(), 'path' => $path, ...$metadata];
            $parent = $this->folders($source, array_slice(explode('/', $relative), 0, -1));
            $this->put($source, hash('sha256', 'disk:'.$relative), $parent, basename($path), $descriptor);
            $count++;
        }

        return $count;
    }

    /** Remove only the catalog reference after the owning application deletes its file. */
    public function forgetDisk(Principal $principal, string $key, string $path): void
    {
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'disk', 422);
        $relative = $source->relative($path);
        $this->query($source)->where('identity', hash('sha256', 'disk:'.$relative))->where('kind', 'file')->delete();
    }

    /** A keyset batch; returns the last visited media key, without scanning the entire source. */
    public function indexSpatie(Principal $principal, string $key, ?string $after = null, int $limit = 60): array
    {
        abort_unless($limit >= 1 && $limit <= 120, 422);
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'spatie', 422);
        $query = $source->mediaQuery($principal);
        $column = $query->getModel()->getQualifiedKeyName();
        $rows = $query->when($after !== null, fn ($q) => $q->where($column, '>', $after))->orderBy($column)->limit($limit)->get();
        foreach ($rows as $media) {
            $path = $source->path($media->getPathRelativeToRoot());
            $mount = $source->spatie($media);
            $descriptor = ['disk' => (string) $media->disk, 'path' => $path, 'mime' => (string) $media->mime_type, 'size' => (int) $media->size,
                'identity' => $mount->identity($media), 'fingerprint' => $mount->fingerprint($media, $path),
                'thumbnail' => $mount->thumbnail($media), 'preview' => $mount->preview($media)];
            $type = (string) ($media->getAttribute('model_type') ?: 'Media');
            $label = $source->config['model_labels'][$type] ?? class_basename($type);
            $collection = (string) ($media->getAttribute('collection_name') ?: 'default');
            $parent = $this->folders($source, [$label, $collection], [$type, $collection]);
            $this->put($source, $mount->identity($media), $parent, (string) ($media->getAttribute('file_name') ?: $media->getKey()), $descriptor, (string) $media->getKey());
        }

        return ['indexed' => $rows->count(), 'after' => $rows->isEmpty() ? null : (string) $rows->last()->getKey(), 'hasMore' => $rows->count() === $limit];
    }

    public function indexModels(Principal $principal, string $key, ?string $after = null, int $limit = 60): array
    {
        abort_unless($limit >= 1 && $limit <= 120, 422);
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'model', 422);
        $query = $source->modelQuery($principal, static fn (ModelQuery $query, string $column) => $query
            ->when($after !== null, fn ($q) => $q->where($column, '>', $after))->orderBy($column)->limit($limit));

        return $this->projectModels($source, $query, $limit);
    }

    /** Call after the owning application commits. Re-read the model instead of accepting a queued snapshot. */
    public function syncModel(Principal $principal, string $key, string $ownerId): void
    {
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'model', 422);
        $result = $this->projectModels($source, $source->modelQuery($principal, static fn (ModelQuery $query) => $query->whereKey($ownerId)), 1);
        abort_if($result['indexed'] === 0, 404);
    }

    /** Forget catalog references after a hard delete. Soft-deleted owners remain restorable. */
    public function forgetDeletedModel(Principal $principal, string $key, string $ownerId): void
    {
        $source = $this->source($principal, $key, true);
        abort_unless($source->config['type'] === 'model', 422);
        $model = $source->modelClass();
        DB::transaction(function () use ($source, $ownerId, $model): void {
            $rows = $this->query($source)->where('kind', 'file')->where('remote_id', $ownerId);
            $ids = $rows->lockForUpdate()->pluck('id');
            if ($ids->isNotEmpty() && ! (new $model)->newQueryWithoutScopes()->whereKey($ownerId)->useWritePdo()->exists()) {
                $rows->whereIn('id', $ids)->delete();
            }
        }, 3);
    }

    private function projectModels(CatalogSource $source, ModelQuery $query, int $limit): array
    {
        if ($query->getModel()->getConnection() !== DB::connection()) {
            throw new LogicException('A registered media owner must share the application catalog database connection.');
        }

        return DB::transaction(function () use ($source, $query, $limit): array {
            $owners = $query->lockForUpdate()->get();
            $stores = $source->modelStores();
            $revision = $source->revision();
            $records = [];
            $identities = [];
            foreach ($owners as $owner) {
                $ownerId = (string) $owner->getKey();
                foreach ($this->registrations($owner, $source, $stores) as $media) {
                    $identity = hash('sha256', json_encode(['model', $owner::class, $ownerId, $media->key], JSON_THROW_ON_ERROR));
                    $identities[$identity] = true;
                    $records[] = $this->record($source, $revision, $identity, hash('sha256', 'root'), $media->name ?? basename($media->original->path), $media->descriptor(), $ownerId);
                }
            }
            if ($owners->isNotEmpty()) {
                $existing = DB::table('fms_application_entries')->where('tenant_scope', $source->tenantScope())
                    ->where('source_key', $source->key)->where('kind', 'file')->whereIn('remote_id', array_map('strval', $owners->modelKeys()));
                $indexed = $existing->get(['identity', 'source_revision', 'name', 'descriptor'])->keyBy('identity');
                $stale = $indexed->keys()->reject(fn (string $identity) => isset($identities[$identity]));
                foreach ($stale->chunk(120) as $chunk) {
                    (clone $existing)->whereIn('identity', $chunk->values()->all())->delete();
                }
                $changed = array_filter($records, static function (array $record) use ($indexed): bool {
                    $previous = $indexed->get($record['identity']);

                    return $previous === null || $previous->source_revision !== $record['source_revision']
                        || $previous->name !== $record['name'] || $previous->descriptor !== $record['descriptor'];
                });
                foreach (array_chunk($changed, 60) as $chunk) {
                    $this->upsert($chunk);
                }
            }

            return ['indexed' => $owners->count(), 'after' => $owners->isEmpty() ? null : (string) $owners->last()->getKey(), 'hasMore' => $owners->count() === $limit];
        }, 3);
    }

    /** @return iterable<ApplicationMedia> */
    private function registrations(RegistersApplicationMedia $owner, CatalogSource $source, array $stores): iterable
    {
        $seen = [];
        foreach ($owner->studioMedia() as $media) {
            if (! $media instanceof ApplicationMedia || count($seen) >= 32 || isset($seen[$media->key])) {
                throw new LogicException('Register up to 32 application media files with unique slot keys per model.');
            }
            $seen[$media->key] = true;
            foreach ([$media->original, ...array_values($media->variants)] as $file) {
                $path = $source->path($file->path);
                $prefixes = $stores[$file->disk] ?? [];
                abort_unless(array_filter($prefixes, static fn (string $prefix): bool => $prefix === '' || str_starts_with($path, $prefix.'/')) !== [], 422, 'Object is outside this application source.');
            }
            yield $media;
        }
    }

    /** Revalidate per-record access with one scoped query per model source on a page. */
    public function visible(CatalogSource $source, Principal $principal, array $rows): array
    {
        if ($source->config['type'] === 'model') {
            $ids = array_values(array_unique(array_filter(array_column($rows, 'remote_id'), fn ($id) => $id !== null)));
            if ($ids === []) {
                return [];
            }
            $query = $source->modelQuery($principal, static fn (ModelQuery $query) => $query->whereKey($ids));
            $visible = array_fill_keys($query->pluck($query->getModel()->getQualifiedKeyName())->all(), true);

            return array_values(array_filter($rows, fn (object $row) => isset($visible[$row->remote_id])));
        }
        if ($source->config['type'] !== 'spatie') {
            return $rows;
        }
        $ids = array_values(array_filter(array_map(fn (object $row) => $row->remote_id, $rows)));
        $query = $source->mediaQuery($principal);
        $models = $ids === [] ? collect() : $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids)->get()->keyBy(fn (Model $media) => (string) $media->getKey());

        return array_values(array_filter($rows, function (object $row) use ($source, $models): bool {
            if ($row->kind === 'folder') {
                return true;
            }
            $media = $models->get($row->remote_id);
            if ($media === null) {
                return false;
            }
            $data = json_decode($row->descriptor, true, 16, JSON_THROW_ON_ERROR);

            return $source->spatie()->fingerprint($media, $media->getPathRelativeToRoot()) === $data['fingerprint'];
        }));
    }

    public function content(CatalogSource $source, Principal $principal, object $row, string $variant, bool $missingThumbnail = false): object
    {
        abort_unless($row->kind === 'file', 404);
        $data = json_decode($row->descriptor, true, 16, JSON_THROW_ON_ERROR);
        if ($source->config['type'] === 'model') {
            $this->assertCurrentRegistration($source, $principal, $row, $data, $missingThumbnail ? 'thumbnail' : $variant);
        } else {
            abort_unless($this->visible($source, $principal, [$row]) !== [], 404);
        }
        if ($source->config['type'] === 'disk') {
            $source->relative($data['path']);
            $current = DiskObjectMetadata::read(Storage::disk($data['disk']), $data['path']);
            abort_unless($current['size'] === $data['size'] && $current['modified'] === $data['modified'] && $current['mime'] === $data['mime'], 409, 'Source changed. Refresh its catalog.');
        }
        if ($variant !== 'original') {
            $conversion = $data[$variant] ?? null;
            abort_unless(is_array($conversion), 404);
            $data = ['size' => null, ...$conversion];
        }

        return (object) ['disk' => $data['disk'], 'object_key' => $data['path'], 'mime_type' => $data['mime'], 'size_bytes' => $data['size'],
            'name' => $source->config['type'] === 'model' ? basename($data['path']) : $row->name];
    }

    private function assertCurrentRegistration(CatalogSource $source, Principal $principal, object $row, array $indexed, string $variant): void
    {
        $owner = $source->modelQuery($principal, static fn (ModelQuery $query) => $query->whereKey($row->remote_id))->first();
        abort_unless($owner instanceof RegistersApplicationMedia, 404);
        foreach ($this->registrations($owner, $source, $source->modelStores()) as $media) {
            if ($media->key !== $indexed['slot']) {
                continue;
            }
            $current = $media->descriptor();
            abort_unless($current['revision'] === $indexed['revision']
                && $media->original->descriptor() === array_intersect_key($indexed, $media->original->descriptor())
                && ($variant === 'original' || ($current[$variant] ?? null) === ($indexed[$variant] ?? null)), 409, 'Source changed. Refresh its catalog.');

            return;
        }
        abort(404);
    }

    private function folders(CatalogSource $source, array $labels, ?array $identities = null): string
    {
        $parent = hash('sha256', 'root');
        $path = [];
        foreach ($labels as $index => $label) {
            $path[] = $identities[$index] ?? $label;
            $identity = hash('sha256', 'folder:'.json_encode($path, JSON_THROW_ON_ERROR));
            $this->put($source, $identity, $parent, $label, null);
            $parent = $identity;
        }

        return $parent;
    }

    private function put(CatalogSource $source, string $identity, string $parent, string $name, ?array $descriptor, ?string $remoteId = null): void
    {
        $this->upsert([$this->record($source, $source->revision(), $identity, $parent, $name, $descriptor, $remoteId)]);
    }

    private function record(CatalogSource $source, string $revision, string $identity, string $parent, string $name, ?array $descriptor, ?string $remoteId): array
    {
        abort_unless($name !== '' && mb_check_encoding($name, 'UTF-8') && strlen($name) <= 255 && ($remoteId === null || strlen($remoteId) <= 191), 422);

        return [
            'public_id' => (string) Str::ulid(), 'tenant_scope' => $source->tenantScope(), 'source_key' => $source->key,
            'source_revision' => $revision, 'identity' => $identity, 'parent_key' => $parent,
            'kind' => $descriptor === null ? 'folder' : 'file', 'name' => $name, 'sort_name' => mb_strtolower($name),
            'remote_id' => $remoteId, 'descriptor' => $descriptor === null ? null : json_encode($descriptor, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function upsert(array $rows): void
    {
        DB::table('fms_application_entries')->upsert($rows, ['tenant_scope', 'source_key', 'identity'], ['source_revision', 'parent_key', 'name', 'sort_name', 'remote_id', 'descriptor', 'updated_at']);
    }
}
