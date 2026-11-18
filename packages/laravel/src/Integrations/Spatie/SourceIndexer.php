<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Spatie;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

final class SourceIndexer
{
    public function __construct(private WorkspaceAccess $access) {}

    /** @return array{processed: int, nextCursor: ?string} */
    public function syncPage(Principal $principal, string $workspaceId, string $mountKey, ?string $cursor = null, int $limit = 60, ?callable $fence = null, bool $worker = false): array
    {
        abort_unless($limit >= 1 && $limit <= 120, 422);

        return DB::transaction(function () use ($principal, $workspaceId, $mountKey, $cursor, $limit, $fence, $worker): array {
            $workspace = $worker ? $this->workerWorkspace($principal, $workspaceId) : $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            if ($fence !== null) {
                $fence();
            }
            $mount = SourceMount::find($mountKey);
            abort_if($mount === null, 404);
            $query = $mount->query($workspace, $principal);
            $model = $query->getModel();
            $key = $model->getQualifiedKeyName();
            $scope = hash('sha256', json_encode([$principal->key(), $workspaceId, $mountKey, $model::class, $model->getTable(), $model->getConnection()->getName()], JSON_THROW_ON_ERROR));
            if ($cursor !== null) {
                try {
                    $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
                } catch (DecryptException|JsonException) {
                    abort(422, 'Invalid Spatie discovery cursor.');
                }
                abort_unless(is_array($payload) && ($payload['scope'] ?? '') === $scope && ($payload['expires'] ?? 0) > time() && is_string($payload['after'] ?? null), 422, 'The discovery cursor belongs to another scope.');
                $query->where($key, '>', $payload['after']);
            }
            if ($model instanceof Media) {
                $query->with('model');
                $relations = $mount->config['tag_relations'] ?? [];
                if (! is_array($relations) || count($relations) > 8 || array_filter($relations, fn ($relation) => ! is_string($relation) || ! preg_match('/\\A[a-zA-Z_][a-zA-Z0-9_.]{0,127}\\z/', $relation))) {
                    throw new LogicException('Configure valid source tag relation names.');
                }
                $query->with($relations);
            }
            $rows = $query->orderBy($key)->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
            if ($rows->isNotEmpty()) {
                $parentId = $this->rootFolder($workspace, $mount);
                foreach ($rows as $media) {
                    $this->writeProjection($workspace, $mount, $parentId, $media);
                }
            }
            $after = $rows->last()?->getKey();

            return ['processed' => $rows->count(), 'nextCursor' => ! $hasMore || $after === null ? null : Crypt::encryptString(json_encode(['scope' => $scope, 'after' => (string) $after, 'expires' => time() + ($worker ? 31536000 : 3600)], JSON_THROW_ON_ERROR))];
        }, 3);
    }

    public function project(Principal $principal, string $workspaceId, SourceMount $mount, Model $media): void
    {
        DB::transaction(function () use ($principal, $workspaceId, $mount, $media): void {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $mount = SourceMount::find($mount->key);
            abort_if($mount === null, 404);
            $current = $mount->query($workspace, $principal)->whereKey($media->getKey())->first();
            abort_if($current === null || $current::class !== $media::class || (string) $current->getKey() !== (string) $media->getKey(), 404);
            $this->writeProjection($workspace, $mount, $this->rootFolder($workspace, $mount), $current);
        }, 3);
    }

    public function refreshForWorker(object $workspace, SourceMount $mount, Principal $principal, iterable $mappings, callable $missing): void
    {
        $workspace = $this->workerWorkspace($principal, $workspace->public_id);
        $mappings = collect($mappings);
        $query = $mount->query($workspace, $principal);
        if ($query->getModel() instanceof Media) {
            $query->with('model');
        }
        $relations = $mount->config['tag_relations'] ?? [];
        if ($relations !== []) {
            $query->with($relations);
        }
        $root = $mappings->isEmpty() ? null : $this->rootFolder($workspace, $mount);
        $models = $query->whereKey($mappings->pluck('media_key')->all())->get()->keyBy(fn ($media): string => $mount->identity($media));
        foreach ($mappings as $mapping) {
            $media = $models->get($mapping->source_key);
            if ($media === null) {
                $missing($mapping);

                continue;
            }
            $this->writeProjection($workspace, $mount, $root, $media);
        }
    }

    private function workerWorkspace(Principal $principal, string $workspaceId): object
    {
        $identity = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first(['tenant_type', 'tenant_id', 'tenant_key']);
        abort_if($identity === null, 404);

        return $this->access->resolveForWorker($principal, $workspaceId, TenantIdentity::fromWorkspace($identity), write: true, lock: true, operation: 'sources.sync');
    }

    private function writeProjection(object $workspace, SourceMount $mount, int $parentId, Model $media): void
    {
        if (! method_exists($media, 'getPathRelativeToRoot')) {
            throw new LogicException('A Spatie Media model with getPathRelativeToRoot() is required.');
        }
        $mediaKey = (string) $media->getKey();
        abort_if($mediaKey === '' || strlen($mediaKey) > 191, 422, 'Unsupported source identity.');
        $path = $media->getPathRelativeToRoot();
        $disk = (string) $media->getAttribute('disk');
        $mime = (string) ($media->getAttribute('mime_type') ?: 'application/octet-stream');
        $size = (int) $media->getAttribute('size');
        abort_unless($path !== '' && strlen($path) <= 1024 && $disk !== '' && strlen($disk) <= 255 && strlen($mime) <= 160 && $size >= 0, 422, 'Invalid source media metadata.');
        $sourceKey = $mount->identity($media);
        $fingerprint = $mount->fingerprint($media, $path);
        $mappings = DB::table('fms_spatie_sources as s')->join('fms_entries as e', 's.entry_id', '=', 'e.id')
            ->where('s.workspace_id', $workspace->id)->where('s.mount_key', $mount->key)->whereNotNull('s.media_key');
        $mapping = (clone $mappings)->where('s.source_key', $sourceKey)->first(['s.id as mapping_id', 's.source_revision', 'e.*']);
        if ($mapping === null) {
            // Adopt a legacy projection only while the original source revision still matches.
            $legacyRevision = hash('sha256', json_encode([$disk, $path, $mime, $size, $media->getAttribute('updated_at')], JSON_THROW_ON_ERROR));
            $mapping = $mappings->where('s.source_key', hash('sha256', $mediaKey))->where('s.source_revision', $legacyRevision)
                ->first(['s.id as mapping_id', 's.source_revision', 'e.*']);
        }
        $name = $this->label((string) ($media->getAttribute('file_name') ?: $media->getAttribute('name')));
        $nameKey = mb_strtolower($name, 'UTF-8');
        $conflicts = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_scope', $parentId)->where('kind', 'file')->whereNull('trashed_at');
        if ($mapping !== null) {
            $conflicts->where('id', '<>', $mapping->id);
        }
        if ((clone $conflicts)->where('normalized_name', $nameKey)->exists()) {
            $name = $this->conflictLabel($name, substr($sourceKey, 0, 16));
            $nameKey = mb_strtolower($name, 'UTF-8');
            if ((clone $conflicts)->where('normalized_name', $nameKey)->exists()) {
                $name = $this->conflictLabel((string) ($media->getAttribute('file_name') ?: $media->getAttribute('name')), $sourceKey);
                $nameKey = mb_strtolower($name, 'UTF-8');
            }
        }
        if ($mapping !== null) {
            DB::table('fms_spatie_sources')->where('entry_id', $mapping->id)->update(['missing_since' => null, 'missing_at' => null, 'missing_checks' => 0]);
        }
        $version = $mapping !== null && $mapping->source_revision === $fingerprint && $mapping->current_version_id !== null
            ? $mapping->current_version_id : (string) Str::ulid();
        $thumbnail = $mount->thumbnail($media);
        $preview = $mount->preview($media);
        $properties = $media->getAttribute('custom_properties');
        $metadata = array_filter(array_intersect_key(is_array($properties) ? $properties : [], array_flip(['alt', 'caption', 'description'])), is_string(...));
        // Source dimensions are indexed metadata, never a filesystem probe during browsing.
        $dimensions = $mount->config['dimensions_using'] ?? null;
        $dimensions = is_callable($dimensions) ? $dimensions($media) : null;
        $width = is_array($dimensions) ? ($dimensions['width'] ?? null) : null;
        $height = is_array($dimensions) ? ($dimensions['height'] ?? null) : null;
        $validDimensions = is_int($width) && is_int($height) && $width > 0 && $height > 0 && $width <= 1000000 && $height <= 1000000;

        $values = ['parent_id' => $parentId, 'parent_scope' => $parentId, 'kind' => 'file', 'sort_group' => 1,
            'name' => $name, 'normalized_name' => $nameKey, 'media_type' => substr(explode('/', $mime)[0], 0, 24), 'mime_type' => $mime,
            'width' => $validDimensions ? $width : null, 'height' => $validDimensions ? $height : null,
            'size_bytes' => $size, 'source_kind' => 'spatie', 'current_version_id' => $version, 'trashed_at' => null,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'thumbnail_state' => $thumbnail === null ? 'none' : 'ready', 'thumbnail_disk' => $thumbnail['disk'] ?? null,
            'thumbnail_key' => $thumbnail['path'] ?? null, 'thumbnail_mime_type' => $thumbnail['mime'] ?? null,
            'thumbnail_version_id' => $thumbnail === null ? null : $version,
            'preview_state' => $preview === null ? 'none' : 'ready', 'preview_disk' => $preview['disk'] ?? null,
            'preview_key' => $preview['path'] ?? null, 'preview_mime_type' => $preview['mime'] ?? null,
            'preview_version_id' => $preview === null ? null : $version,
            'preview_preset' => $preview === null ? null : 'spatie-preview'];
        if ($mapping !== null && array_intersect_key((array) $mapping, $values) == $values) {
            $this->projectTags($workspace, $mount, (int) $mapping->id, $media);

            return;
        }
        if ($mapping === null) {
            $entryId = DB::table('fms_entries')->insertGetId([...$values, 'workspace_id' => $workspace->id, 'public_id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_spatie_sources')->insert(['workspace_id' => $workspace->id, 'mount_key' => $mount->key, 'media_key' => $mediaKey, 'source_key' => $sourceKey, 'source_revision' => $fingerprint, 'entry_id' => $entryId, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $entryId = $mapping->id;
            DB::table('fms_entries')->where('id', $entryId)->update([...$values, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            DB::table('fms_spatie_sources')->where('id', $mapping->mapping_id)->update(['source_key' => $sourceKey, 'source_revision' => $fingerprint, 'updated_at' => now()]);
        }
        app(NavigationChanges::class)->record((int) $workspace->id, 'source', []);
        $this->projectTags($workspace, $mount, (int) $entryId, $media);
        if ($mapping === null || $mapping->current_version_id !== $version) {
            DB::table('fms_asset_versions')->insert(['id' => $version, 'entry_id' => $entryId, 'disk' => $disk, 'object_key' => $path, 'sha256' => null, 'size_bytes' => $size, 'mime_type' => $mime, 'created_at' => now()]);
        }
    }

    private function conflictLabel(string $name, string $suffix): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $extension = preg_match('/\\A[a-zA-Z0-9][a-zA-Z0-9+_-]{0,31}\\z/', $extension) ? '.'.$extension : '';
        $base = $extension === '' ? $name : mb_substr($name, 0, -mb_strlen($extension));

        return mb_substr($base, 0, 188 - strlen($suffix) - strlen($extension)).' · '.$suffix.$extension;
    }

    private function projectTags(object $workspace, SourceMount $mount, int $entryId, Model $media): void
    {
        $using = $mount->config['tags_using'] ?? null;
        if ($using === null) {
            return;
        }
        if (! is_callable($using)) {
            throw new LogicException('Source tags_using must be callable.');
        }
        $tags = $using($media);
        if (! is_array($tags)) {
            throw new LogicException('Source tags_using must return a bounded tag list.');
        }
        app(EntryTags::class)->project($workspace, $entryId, $tags);
    }

    private function rootFolder(object $workspace, SourceMount $mount): int
    {
        $root = DB::table('fms_spatie_sources')->where('workspace_id', $workspace->id)->where('mount_key', $mount->key)->whereNull('media_key')->first();
        if ($root !== null) {
            return (int) $root->entry_id;
        }
        $key = hash('sha256', "mount\0root");
        $name = $this->label((string) ($mount->config['label'] ?? 'Spatie Media'), 110).' · '.$mount->key;
        $conflicts = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_scope', 0)->where('kind', 'folder')->whereNull('trashed_at');
        if ($conflicts->where('normalized_name', mb_strtolower($name, 'UTF-8'))->exists()) {
            $name = mb_substr($name, 0, 162).' · '.Str::ulid();
        }
        $id = DB::table('fms_entries')->insertGetId(['workspace_id' => $workspace->id, 'public_id' => (string) Str::ulid(), 'kind' => 'folder', 'sort_group' => 0, 'parent_scope' => 0, 'name' => $name, 'normalized_name' => mb_strtolower($name, 'UTF-8'), 'source_kind' => 'spatie', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_spatie_sources')->insert(['workspace_id' => $workspace->id, 'mount_key' => $mount->key, 'media_key' => null, 'source_key' => $key, 'source_revision' => $key, 'entry_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        app(NavigationChanges::class)->record((int) $workspace->id, 'source', []);

        return $id;
    }

    private function label(string $name, int $length = 191): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '_', str_replace(['/', '\\'], '_', $name)) ?? '');

        return $name === '' ? 'Media' : mb_substr($name, 0, $length);
    }
}
