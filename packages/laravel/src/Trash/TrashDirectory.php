<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Identity\Principal;

final class TrashDirectory
{
    public function __construct(private WorkspaceAccess $access) {}

    public function page(Principal $principal, string $workspace, array $data, ?string $cursorScope = null): array
    {
        $resolved = $this->access->resolve($principal, $workspace);
        $tab = $data['tab'] ?? 'all';
        $actor = $data['actor'] ?? 'all';
        $after = isset($data['after']) ? Carbon::parse($data['after'])->utc()->toDateTimeString() : null;
        $codec = new ScopedCursor(hash('sha256', json_encode([$workspace, $principal->key(), $tab, $actor, $after, $cursorScope], JSON_THROW_ON_ERROR)));
        $cursor = $codec->decode($data['cursor'] ?? null);
        if ($tab === 'restorations') {
            $query = DB::table('fms_activity as a')->where('a.workspace_id', $resolved->id)->where('a.event', 'restored')
                ->when($actor === 'me', fn ($q) => $q->where('a.actor_key', $principal->key()))
                ->when($after !== null, fn ($q) => $q->where('a.created_at', '>=', $after))->orderByDesc('a.id');
            $page = $query->cursorPaginate((int) ($data['limit'] ?? 60), ['a.*'], 'cursor', $cursor);
            $available = DB::table('fms_entries')->where('workspace_id', $resolved->id)->whereNull('trashed_at')->whereNull('purging_at')
                ->whereIn('public_id', array_column($page->items(), 'entry_public_id'))->pluck('public_id')->all();
            $items = array_map(static fn ($row): array => [
                'id' => $row->entry_public_id, 'activityId' => $row->public_id, 'name' => $row->name, 'kind' => $row->kind,
                'mediaType' => $row->media_type, 'size' => $row->size_bytes === null ? null : (int) $row->size_bytes,
                'revision' => (int) $row->revision, 'trashedAt' => null, 'occurredAt' => Carbon::parse($row->created_at, 'UTC')->toISOString(),
                'actor' => ['type' => $row->actor_type, 'id' => $row->actor_id],
                'canRestore' => false, 'available' => in_array($row->entry_public_id, $available, true),
            ], $page->items());
        } else {
            $query = DB::table('fms_entries')->where('workspace_id', $resolved->id)->whereNotNull('trashed_at')->where('source_kind', 'managed')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_entries as parent')->whereColumn('parent.id', 'fms_entries.parent_id')->whereNotNull('parent.trashed_at'))
                ->when($tab !== 'all', fn ($q) => $q->where('kind', $tab === 'folders' ? 'folder' : 'file'))
                ->when($actor === 'me', fn ($q) => $q->where('trashed_by_key', $principal->key()))
                ->when($after !== null, fn ($q) => $q->where('trashed_at', '>=', $after))->orderByDesc('trashed_at')->orderByDesc('id');
            $page = $query->cursorPaginate((int) ($data['limit'] ?? 60), ['id', 'public_id', 'name', 'kind', 'media_type', 'size_bytes', 'revision', 'trashed_at', 'trashed_by_type', 'trashed_by_id', 'purging_at', 'trash_group_id'], 'cursor', $cursor);
            $write = in_array($resolved->role, ['owner', 'admin', 'editor'], true);
            $purgingGroups = DB::table('fms_tree_operations')->where('workspace_id', $resolved->id)->whereIn('id', array_filter(array_column($page->items(), 'trash_group_id')))->whereNotNull('purging_at')->pluck('id')->all();
            $items = array_map(static fn ($row): array => [
                'id' => $row->public_id, 'name' => $row->name, 'kind' => $row->kind, 'mediaType' => $row->media_type,
                'size' => $row->size_bytes === null ? null : (int) $row->size_bytes, 'revision' => (int) $row->revision,
                'trashedAt' => Carbon::parse($row->trashed_at, 'UTC')->toISOString(), 'occurredAt' => Carbon::parse($row->trashed_at, 'UTC')->toISOString(),
                'actor' => $row->trashed_by_type === null ? null : ['type' => $row->trashed_by_type, 'id' => $row->trashed_by_id],
                'canRestore' => $write && $row->purging_at === null && ! in_array($row->trash_group_id, $purgingGroups, true), 'purging' => $row->purging_at !== null || in_array($row->trash_group_id, $purgingGroups, true),
            ], $page->items());
        }

        return ['items' => $items, 'nextCursor' => $codec->encode($page->nextCursor()),
            'previousCursor' => $codec->encode($page->previousCursor()), 'hasMore' => $page->hasMorePages(),
            'retention' => ['enabled' => (bool) config('media-studio.trash.retention_enabled', false), 'days' => max(1, (int) config('media-studio.trash.days', 30))]];
    }
}
