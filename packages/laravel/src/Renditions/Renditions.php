<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;

final class Renditions
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'];

    public static function published(): Builder
    {
        return DB::table('fms_renditions')->where(function (Builder $query): void {
            $query->where('state', 'ready')->orWhere(fn (Builder $replacement) => $replacement
                ->where('owned', true)->whereNotNull('replacement_recipe')->whereNotNull('object_key'));
        });
    }

    public function project(int $entryId, string $versionId, string $preset, string $state, ?object $rendition = null): void
    {
        $entry = $state === 'queued' ? null : DB::table('fms_entries')->where('id', $entryId)->where('current_version_id', $versionId)
            ->first(['thumbnail_preset', 'preview_preset']);
        foreach (['thumbnail' => 'thumbnails', 'preview' => 'preview'] as $role => $config) {
            $selected = $entry?->{$role.'_preset'} ?? config('media-studio.processing.'.$config.'.preset', ($role === 'preview' ? 'large-v1' : 'thumbnail-v1'));
            if ($selected !== $preset) {
                continue;
            }
            $this->projectRole($entryId, $versionId, $role, $state, $rendition ?? (object) ['preset' => $preset]);
        }
    }

    public function projectRole(int $entryId, string $versionId, string $role, string $state, ?object $rendition): void
    {
        if (! in_array($role, ['thumbnail', 'preview'], true)) {
            throw new LogicException('Unsupported display role.');
        }
        $ready = $state === 'ready' && $rendition !== null;
        $changes = [$role.'_state' => $state];
        if ($ready) {
            $changes += [
                $role.'_disk' => $rendition->disk, $role.'_key' => $rendition->object_key,
                $role.'_mime_type' => $rendition->mime_type, $role.'_version_id' => $versionId,
                $role.'_preset' => $rendition->preset,
            ];
        } else {
            $changes += [$role.'_disk' => null, $role.'_key' => null, $role.'_mime_type' => null,
                $role.'_version_id' => $rendition === null ? null : $versionId, $role.'_preset' => $rendition->preset ?? null];
        }
        DB::table('fms_entries')->where('id', $entryId)->where('current_version_id', $versionId)->update($changes);
    }

    public function provider(string $key): ExternalVariantProvider
    {
        $type = config('media-studio.processing.external_providers.'.$key);
        if (! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $key) || ! is_string($type)) {
            abort(404, 'Variant provider is unavailable.');
        }
        $provider = app($type);
        if (! $provider instanceof ExternalVariantProvider) {
            throw new LogicException('A variant provider must implement ExternalVariantProvider.');
        }

        return $provider;
    }

    /** @return array<string, ExternalVariant> */
    public function external(string $provider, object $workspace, object $entry, object $version, Principal $principal): array
    {
        $variants = [];
        foreach ($this->provider($provider)->variants($workspace, $entry, $version, $principal) as $variant) {
            abort_unless($variant instanceof ExternalVariant && count($variants) < 64 && ! isset($variants[$variant->name]), 422, 'Invalid external variant catalogue.');
            abort_unless(in_array($variant->mimeType, self::IMAGE_MIMES, true) && $variant->sizeBytes > 0, 422, 'A display variant must be an image.');
            Storage::disk($variant->disk);
            $variants[$variant->name] = $variant;
        }

        return $variants;
    }

    /** @param array{thumbnail?:string,preview?:string} $roles */
    public function syncExternal(Principal $principal, string $workspaceId, string $entryId, string $provider, array $roles = []): void
    {
        $workspace = app(WorkspaceAccess::class)->resolve($principal, $workspaceId, write: true);
        $entry = app(Entries::class)->resolve($workspace->id, $entryId);
        app(SourcePolicy::class)->managed($entry);
        abort_if($entry->kind !== 'file' || $entry->trashed_at !== null, 404);
        $version = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->where('entry_id', $entry->id)->first();
        abort_if($version === null, 404);
        $variants = $this->external($provider, $workspace, $entry, $version, $principal);
        $roles += ['thumbnail' => config('media-studio.processing.thumbnails.preset', 'thumbnail-v1'), 'preview' => config('media-studio.processing.preview.preset', 'large-v1')];
        abort_if(array_diff(array_keys($roles), ['thumbnail', 'preview']) !== [], 422, 'Invalid display roles.');

        DB::transaction(function () use ($principal, $workspaceId, $entry, $version, $provider, $variants, $roles): void {
            $workspace = app(WorkspaceAccess::class)->resolve($principal, $workspaceId, write: true, lock: true);
            $current = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $entry->id)->whereNull('trashed_at')->lockForUpdate()->first();
            abort_unless($current !== null && $current->current_version_id === $version->id, 409, 'The source version changed.');
            $rows = DB::table('fms_renditions')->where('version_id', $version->id)->whereIn('preset', array_keys($variants))->get()->keyBy('preset');
            foreach ($variants as $variant) {
                $existing = $rows[$variant->name] ?? null;
                abort_if($existing !== null && ($existing->owned || $existing->provider !== $provider), 409, 'The variant name is already owned by another pipeline.');
                DB::table('fms_renditions')->updateOrInsert(['version_id' => $version->id, 'preset' => $variant->name], [
                    'entry_id' => $entry->id, 'state' => 'ready', 'owned' => false, 'provider' => $provider,
                    'disk' => $variant->disk, 'object_key' => $variant->objectKey, 'mime_type' => $variant->mimeType,
                    'size_bytes' => $variant->sizeBytes, 'width' => $variant->width, 'height' => $variant->height,
                    'sha256' => $variant->sha256, 'created_at' => $existing->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
            DB::table('fms_renditions')->where('version_id', $version->id)->where('provider', $provider)->where('owned', false)
                ->whereNotIn('preset', array_keys($variants))->update(['state' => 'unavailable', 'updated_at' => now()]);
            foreach ($roles as $role => $name) {
                $variant = $variants[$name] ?? null;
                $row = $variant === null ? null : (object) [
                    'preset' => $variant->name, 'disk' => $variant->disk, 'object_key' => $variant->objectKey, 'mime_type' => $variant->mimeType,
                ];
                $this->projectRole($entry->id, $version->id, $role, $row === null ? 'none' : 'ready', $row);
            }
        }, 3);
    }

    public function assertExternal(object $workspace, object $entry, object $rendition, Principal $principal, ?array $catalogue = null): void
    {
        if ($rendition->owned) {
            return;
        }
        if ($catalogue === null) {
            $version = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->where('id', $rendition->version_id)->first();
            abort_if($version === null, 404);
            $catalogue = $this->external((string) $rendition->provider, $workspace, $entry, $version, $principal);
        }
        $variant = $catalogue[$rendition->preset] ?? null;
        abort_unless($variant !== null && $variant->disk === $rendition->disk && $variant->objectKey === $rendition->object_key
            && $variant->mimeType === $rendition->mime_type && $variant->sizeBytes === (int) $rendition->size_bytes
            && $variant->sha256 === $rendition->sha256, 404, 'The external variant is unavailable.');
    }
}
