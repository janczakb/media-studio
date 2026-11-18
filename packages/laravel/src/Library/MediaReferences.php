<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Renditions\Renditions;

final class MediaReferences
{
    public function __construct(private WorkspaceAccess $access) {}

    /**
     * Resolve at most120references with one resource query, preserving insertion order.
     * URLs are output only, never the durable identity stored in content/relations.
     *
     * @param  list<array{assetId: string, versionId?: ?string, rendition?: string, slot?: string, metadata?: array}>  $references
     * @return list<array{assetId: string, versionId: string, name: string, mimeType: string, url: string}>
     */
    public function resolve(Principal $principal, string $workspaceId, array $references, bool $availableOnly = false, bool $presentation = false, ?string $thumbnailPreset = null): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId);
        abort_if(count($references) > 120, 422, 'Resolve at most120media references at once.');
        foreach ($references as $reference) {
            abort_unless(is_array($reference) && isset($reference['assetId']) && is_string($reference['assetId']) && Str::isUlid($reference['assetId']), 422, 'Invalid media reference.');
            abort_if(isset($reference['rendition']) && (! is_string($reference['rendition']) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $reference['rendition'])), 422, 'Invalid rendition reference.');
            abort_if(isset($reference['versionId']) && (! is_string($reference['versionId']) || ! Str::isUlid($reference['versionId'])), 422, 'Invalid version reference.');
        }
        $assetIds = array_values(array_unique(array_column($references, 'assetId')));
        $versions = array_values(array_unique(array_filter(array_column($references, 'versionId'))));
        $rows = DB::table('fms_entries as e')->join('fms_asset_versions as v', 'v.entry_id', '=', 'e.id')
            ->where('e.workspace_id', $workspace->id)->where('e.kind', 'file')->whereNull('e.trashed_at')->whereNull('e.purging_at')->whereIn('e.public_id', $assetIds)
            ->where(fn ($q) => $q->whereColumn('v.id', 'e.current_version_id')->orWhereIn('v.id', $versions))
            ->where(fn ($q) => $q->whereNotIn('e.source_kind', ['spatie', 'disk'])->orWhereColumn('v.id', 'e.current_version_id'))
            ->select('e.source_kind', 'e.id', 'e.public_id', 'e.name', 'e.current_version_id', 'v.id as version_id', 'v.mime_type')
            ->when($presentation, fn ($query) => $query->addSelect('e.metadata', 'e.thumbnail_state', 'e.thumbnail_version_id', 'v.size_bytes'))
            ->get();
        $rows = app(SourcePolicy::class)->visible($workspace, $principal, $rows);
        $index = [];
        foreach ($rows as $row) {
            $index[$row->public_id][$row->version_id] = $row;
            if ($row->version_id === $row->current_version_id) {
                $index[$row->public_id]['current'] = $row;
            }
        }
        $renditions = [];
        if (array_filter(array_column($references, 'rendition')) !== []) {
            foreach (Renditions::published()->whereIn('version_id', array_unique(array_column($rows, 'version_id')))
                ->whereIn('preset', array_unique(array_column($references, 'rendition')))->get() as $rendition) {
                $renditions[$rendition->version_id][$rendition->preset] = $rendition;
            }
        }
        $verified = [];
        $catalogues = [];
        $result = [];
        foreach ($references as $reference) {
            $row = $index[$reference['assetId']][$reference['versionId'] ?? 'current'] ?? null;
            if ($availableOnly && $row === null) {
                continue;
            }
            abort_if($row === null, 404, 'A selected file or version is unavailable.');
            // Pinned historic version delivery requires the explicit version route.
            $url = ApiUrl::to('workspaces/'.$workspaceId.'/entries/'.$row->public_id.'/versions/'.$row->version_id.'/content');
            $mime = $row->mime_type;
            $rendition = null;
            if (isset($reference['rendition'])) {
                $rendition = $renditions[$row->version_id][$reference['rendition']] ?? null;
                $available = $rendition !== null && $row->current_version_id === $row->version_id && $rendition->entry_id === $row->id;
                if ($availableOnly && ! $available) {
                    continue;
                }
                abort_unless($available, 404, 'The selected rendition is unavailable.');
                $key = $rendition->id;
                if (! isset($verified[$key])) {
                    $catalogue = null;
                    if (! $rendition->owned) {
                        $providerKey = $row->version_id.':'.$rendition->provider;
                        if (! array_key_exists($providerKey, $catalogues)) {
                            $version = DB::table('fms_asset_versions')->where('id', $row->version_id)->where('entry_id', $row->id)->first();
                            abort_if($version === null, 404);
                            $catalogues[$providerKey] = app(Renditions::class)->external($rendition->provider, $workspace, $row, $version, $principal);
                        }
                        $catalogue = $catalogues[$providerKey];
                    }
                    try {
                        app(Renditions::class)->assertExternal($workspace, $row, $rendition, $principal, $catalogue);
                    } catch (HttpExceptionInterface $error) {
                        if ($availableOnly && in_array($error->getStatusCode(), [403, 404], true)) {
                            continue;
                        }
                        throw $error;
                    }
                    $verified[$key] = true;
                }
                $url = ApiUrl::to('workspaces/'.$workspaceId.'/entries/'.$row->public_id.'/versions/'.$row->version_id.'/renditions/'.$rendition->preset);
                $mime = $rendition->mime_type;
            }
            $file = ['assetId' => $row->public_id, 'versionId' => $row->version_id, 'name' => $row->name, 'mimeType' => $mime, 'url' => $url, ...(isset($reference['rendition']) ? ['rendition' => $reference['rendition']] : [])];
            if ($presentation) {
                $metadata = json_decode($row->metadata ?? '{}', true);
                $alt = $metadata['alt'] ?? null;
                $file += [
                    'alt' => is_string($alt) ? $alt : null,
                    'sizeBytes' => (int) ($rendition->size_bytes ?? $row->size_bytes),
                    'thumbnailUrl' => $row->thumbnail_state === 'ready' && $row->thumbnail_version_id === $row->version_id
                        ? ApiUrl::to('workspaces/'.$workspaceId.'/entries/'.$row->public_id.'/thumbnail').'?v='.$row->version_id
                        : null,
                ];
            }
            $result[] = $file;
        }

        if ($presentation && $thumbnailPreset !== null) {
            $cards = app(ThumbnailProjection::class)->apply($workspaceId,
                array_map(static fn (array $file): array => ['id' => $file['assetId'], 'versionId' => $file['versionId']], $result), $thumbnailPreset);
            foreach ($cards as $index => $card) {
                $result[$index]['thumbnailUrl'] = $card['thumbnailUrl'];
            }
        }

        return $result;
    }

    /**
     * Synchronize already-saved owner relationships in the caller's transaction.
     * Only registered application models are accepted; request class names are not.
     *
     * @param  list<array{assetId: string, versionId?: ?string, rendition?: string, slot: string, metadata?: array}>  $references
     */
    public function sync(Authenticatable $actor, Model $owner, string $attribute, string $workspaceId, array $references): void
    {
        $alias = array_search($owner::class, config('media-studio.usage_owners', []), true);
        abort_unless(is_string($alias) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $alias) && $owner->exists, 422, 'Register a persisted usage owner model.');
        abort_unless(preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,127}\z/', $attribute), 422, 'Invalid usage attribute.');
        Gate::forUser($actor)->authorize('update', $owner);
        $principal = Principal::fromUser($actor);
        $id = (string) $owner->getKey();
        abort_if($id === '' || strlen($id) > 191, 422, 'Invalid owner identity.');
        $ownerKey = hash('sha256', json_encode([$alias, $id], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($owner, $actor, $workspaceId, $principal, $references, $alias, $id, $ownerKey, $attribute): void {
            $owner = $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $owner);
            $workspace = $this->access->resolve($principal, $workspaceId, lock: true);
            abort_if($workspace->tree_operation_id !== null, 423, 'A folder operation is preparing. Retry linking files after it completes.');
            $resolved = $this->resolve($principal, $workspaceId, $references);
            $assetIds = array_column($resolved, 'assetId');
            $entries = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $assetIds)->pluck('id', 'public_id');
            $slots = [];
            $rows = [];
            foreach ($references as $i => $reference) {
                $slot = $reference['slot'] ?? null;
                abort_unless(is_string($slot) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $slot) && ! isset($slots[$slot]), 422, 'Each attachment needs a unique slot.');
                $slots[$slot] = true;
                $metadata = $reference['metadata'] ?? [];
                abort_unless(is_array($metadata) && count(array_diff(array_keys($metadata), ['alt', 'caption', 'position'])) === 0 && strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) <= 4096, 422, 'Unsupported attachment metadata.');
                $rows[] = ['workspace_id' => $workspace->id, 'entry_id' => $entries[$resolved[$i]['assetId']], 'version_id' => $reference['versionId'] ?? null, 'rendition' => $reference['rendition'] ?? null, 'owner_type' => $alias, 'owner_id' => $id, 'owner_key' => $ownerKey, 'attribute' => $attribute, 'slot' => $slot, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('fms_usages')->where('owner_key', $ownerKey)->where('attribute', $attribute)->delete();
            if ($rows !== []) {
                DB::table('fms_usages')->insert($rows);
            }
        }, 3);
    }
}
