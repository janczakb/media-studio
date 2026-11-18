<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Spatie;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use UseFlex\MediaStudio\Identity\Principal;

final class SourceAccess
{
    /** @return list<object> */
    public function filterVisible(object $workspace, Principal $principal, iterable $rows): array
    {
        $rows = is_array($rows) ? array_values($rows) : iterator_to_array($rows, false);
        $external = array_filter($rows, fn (object $row): bool => ($row->source_kind ?? 'managed') === 'spatie');
        if ($external === []) {
            return $rows;
        }
        $mappings = DB::table('fms_spatie_sources')->where('workspace_id', $workspace->id)->whereIn('entry_id', array_column($external, 'id'))->whereNull('missing_at')->get()->groupBy('mount_key');
        $visible = [];
        foreach ($mappings as $mountKey => $members) {
            $mount = SourceMount::find((string) $mountKey);
            if ($mount === null) {
                continue;
            }
            try {
                $query = $mount->query($workspace, $principal);
            } catch (LogicException) {
                continue;
            }
            $keys = $members->whereNotNull('media_key')->pluck('media_key')->all();
            $model = $query->getModel();
            $identityColumns = [$model->getQualifiedKeyName()];
            if ($model instanceof Media) {
                $identityColumns[] = $model->qualifyColumn('uuid');
            }
            $allowed = $keys === [] ? [] : $query->setEagerLoads([])->whereKey($keys)->get($identityColumns)
                ->mapWithKeys(fn (Model $media): array => [$mount->identity($media) => true])->all();
            foreach ($members as $member) {
                if ($member->media_key === null || isset($allowed[$member->source_key])) {
                    $visible[$member->entry_id] = true;
                }
            }
        }

        return array_values(array_filter($rows, fn (object $row): bool => ($row->source_kind ?? 'managed') !== 'spatie' || isset($visible[$row->id])));
    }

    public function assertReadable(object $workspace, Principal $principal, int $entryId): void
    {
        $row = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $entryId)->first(['id', 'source_kind']);
        abort_if($row === null || $this->filterVisible($workspace, $principal, [$row]) === [], 404);
    }

    public function assertOriginal(object $workspace, Principal $principal, object $entry): void
    {
        [$mount, $media, $revision] = $this->currentSource($workspace, $principal, (int) $entry->id, $entry->current_version_id);
        $path = $media->getPathRelativeToRoot();
        abort_unless($entry->object_key === $path && $entry->disk === $media->getAttribute('disk') && $mount->fingerprint($media, $path) === $revision, 404);
    }

    public function thumbnailMime(object $workspace, Principal $principal, object $entry): string
    {
        return $this->conversionMime($workspace, $principal, $entry, 'thumbnail');
    }

    public function previewMime(object $workspace, Principal $principal, object $entry): string
    {
        return $this->conversionMime($workspace, $principal, $entry, 'preview');
    }

    private function conversionMime(object $workspace, Principal $principal, object $entry, string $role): string
    {
        [$mount, $media, $revision] = $this->currentSource($workspace, $principal, (int) $entry->id, $entry->current_version_id);
        $conversion = $role === 'thumbnail' ? $mount->thumbnail($media) : $mount->preview($media);
        abort_unless($conversion !== null
            && $entry->{$role.'_key'} === $conversion['path']
            && $entry->{$role.'_disk'} === $conversion['disk']
            && $mount->fingerprint($media, $media->getPathRelativeToRoot()) === $revision, 404);

        return $conversion['mime'];
    }

    /** @return array{SourceMount, Model, string} */
    private function currentSource(object $workspace, Principal $principal, int $entryId, string $version): array
    {
        $mapping = DB::table('fms_spatie_sources as s')->join('fms_entries as e', 's.entry_id', '=', 'e.id')
            ->where('s.workspace_id', $workspace->id)->where('s.entry_id', $entryId)->whereNull('s.missing_at')->whereNotNull('s.media_key')
            ->where('e.current_version_id', $version)->first(['s.*']);
        abort_if($mapping === null, 404);
        $mount = SourceMount::find($mapping->mount_key);
        abort_if($mount === null, 404);
        try {
            $media = $mount->query($workspace, $principal)->whereKey($mapping->media_key)->first();
        } catch (LogicException) {
            abort(404);
        }
        abort_if($media === null || $mount->identity($media) !== $mapping->source_key || ! method_exists($media, 'getPathRelativeToRoot'), 404);

        return [$mount, $media, $mapping->source_revision];
    }
}
