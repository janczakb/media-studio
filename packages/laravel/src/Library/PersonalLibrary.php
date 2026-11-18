<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class PersonalLibrary
{
    private const IDENTITY = ['workspace_id', 'principal_key', 'entry_id'];

    private const CARD_COLUMNS = [
        'e.id', 'e.public_id', 'e.kind', 'e.name', 'e.media_type', 'e.mime_type', 'e.size_bytes',
        'e.note_excerpt', 'e.note_background', 'e.current_version_id', 'e.updated_at', 'e.revision', 'e.trashed_at', 'e.width', 'e.height',
        'e.thumbnail_state', 'e.thumbnail_version_id', 'e.thumbnail_key', 'e.preview_state', 'e.preview_version_id', 'e.preview_key', 'e.source_kind',
        'p.starred_at as personal_starred_at', 'p.viewed_at as personal_viewed_at',
    ];

    public function __construct(private WorkspaceAccess $access, private Entries $entries, private SourcePolicy $sources) {}

    /** @return array{id: string, starred: bool} */
    public function setStar(Principal $principal, string $workspaceId, string $entryId, bool $starred): array
    {
        return DB::transaction(function () use ($principal, $workspaceId, $entryId, $starred): array {
            [$entry, $identity] = $this->mutationTarget($principal, $workspaceId, $entryId);
            $table = DB::table('fms_personal_entries');
            if ($starred) {
                $at = $this->timestamp();
                if ($table->insertOrIgnore([...$identity, 'starred_at' => $at]) === 0) {
                    $table->where($identity)->whereNull('starred_at')->update(['starred_at' => $at]);
                }
            } else {
                $table->where($identity)->whereNotNull('starred_at')->update(['starred_at' => null]);
                DB::table('fms_personal_entries')->where($identity)->whereNull('starred_at')->whereNull('viewed_at')->delete();
            }

            return ['id' => $entry->public_id, 'starred' => $starred];
        }, 3);
    }

    public function recordView(Principal $principal, string $workspaceId, string $entryId): void
    {
        DB::transaction(function () use ($principal, $workspaceId, $entryId): void {
            [$entry, $identity] = $this->mutationTarget($principal, $workspaceId, $entryId);
            abort_if($entry->kind !== 'file', 422, 'Only file previews can be recorded.');
            DB::table('fms_personal_entries')->upsert([...$identity, 'viewed_at' => $this->timestamp()], self::IDENTITY, ['viewed_at']);
        }, 3);
    }

    /**
     * The caller has already resolved the workspace and filtered these rows by source access.
     *
     * @param  list<object{id: int, public_id: string}>  $rows
     * @return array<string, array{starred: bool, lastViewedAt: ?string}>
     */
    public function hydrate(Principal $principal, object $workspace, array $rows): array
    {
        abort_if(count($rows) > 120, 422, 'Resolve at most 120 personal entry states at once.');
        if ($rows === []) {
            return [];
        }
        $states = DB::table('fms_personal_entries')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())
            ->whereIn('entry_id', array_column($rows, 'id'))->get(['entry_id', 'starred_at', 'viewed_at'])->keyBy('entry_id');
        $result = [];
        foreach ($rows as $row) {
            $state = $states->get($row->id);
            $viewedAt = $state->viewed_at ?? null;
            $result[$row->public_id] = [
                'starred' => ($state->starred_at ?? null) !== null,
                'lastViewedAt' => $viewedAt === null ? null : Carbon::parse($viewedAt, 'UTC')->toISOString(),
            ];
        }

        return $result;
    }

    /** @return array{items: list<object>, nextCursor: ?string, previousCursor: ?string, hasMore: bool} */
    public function page(Principal $principal, string $workspaceId, string $view, ?string $cursor = null, int $limit = 60, string $search = ''): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId);
        $search = $this->normalizeSearch($search);
        $column = $this->sortColumn($view);
        $query = $this->personalQuery($workspace, $principal, $column, $search);
        $scope = $this->scope(['page', $principal->key(), $workspaceId, $view, $search]);
        $token = $this->decodeCursor($cursor, $scope);
        $previous = ($token['direction'] ?? 'next') === 'previous';
        if ($token !== null) {
            $this->seek($query, $column, $token['at'], $token['id'], $previous);
        }
        [$items, $first, $last, $more] = $this->scan($query, $workspace, $principal, $column, $previous, $limit);

        $nextCursor = ($previous ? $token !== null : $more) && $last !== null
            ? $this->encodeCursor($scope, $previous ? $first : $last, 'next') : null;
        $previousCursor = ($previous ? $more : $token !== null) && $first !== null
            ? $this->encodeCursor($scope, $previous ? $last : $first, 'previous') : null;

        return ['items' => $previous ? array_reverse($items) : $items, 'nextCursor' => $nextCursor, 'previousCursor' => $previousCursor, 'hasMore' => $nextCursor !== null];
    }

    /** @return array{items: list<object>, nextCursor: ?string, previousCursor: ?string, hasMore: bool} */
    public function neighbors(Principal $principal, string $workspaceId, string $anchor, string $view, string $search, string $direction, ?string $cursor = null, int $limit = 60): array
    {
        abort_unless(in_array($direction, ['next', 'previous'], true), 422);
        $workspace = $this->access->resolve($principal, $workspaceId);
        $search = $this->normalizeSearch($search);
        $column = $this->sortColumn($view);
        $query = $this->personalQuery($workspace, $principal, $column, $search)->where('e.kind', 'file');
        $anchorRow = (clone $query)->where('e.public_id', $anchor)->first(['e.id', 'e.source_kind', 'p.'.$column.' as personal_sort_at']);
        abort_if($anchorRow === null, 404);
        $this->sources->readable($workspace, $principal, $anchorRow);
        $scope = $this->scope(['neighbors', $principal->key(), $workspaceId, $view, $search, $direction]);
        $token = $this->decodeCursor($cursor, $scope);
        abort_if($token !== null && ($token['anchor'] ?? null) !== $anchor, 422, 'Cursor does not match this preview.');
        $previous = $direction === 'previous';
        $this->seek($query, $column, $token['at'] ?? $anchorRow->personal_sort_at, $token['id'] ?? (int) $anchorRow->id, $previous);
        [$items, , $last, $more] = $this->scan($query, $workspace, $principal, $column, $previous, $limit);
        $edge = last($items);
        $continuation = $more && $last->id !== ($edge->id ?? null)
            ? $this->encodeCursor($scope, $last, $direction, $edge->public_id ?? $anchor) : null;

        return ['items' => $previous ? array_reverse($items) : $items, 'nextCursor' => $previous ? null : $continuation,
            'previousCursor' => $previous ? $continuation : null, 'hasMore' => $more];
    }

    /** @return array{object, array{workspace_id: int, principal_key: string, entry_id: int}} */
    private function mutationTarget(Principal $principal, string $workspaceId, string $entryId): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId);
        $membership = DB::table('fms_memberships')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())
            ->whereIn('role', ['owner', 'admin', 'editor', 'viewer'])->lockForUpdate()->first(['role']);
        abort_if($membership === null, 404);
        $workspace->role = $membership->role;
        $entry = $this->entries->resolve($workspace->id, $entryId);
        abort_if($entry->trashed_at !== null, 404);
        $this->sources->readable($workspace, $principal, $entry);

        return [$entry, ['workspace_id' => (int) $workspace->id, 'principal_key' => $principal->key(), 'entry_id' => (int) $entry->id]];
    }

    private function personalQuery(object $workspace, Principal $principal, string $column, string $search): Builder
    {
        return DB::table('fms_personal_entries as p')->join('fms_entries as e', 'p.entry_id', '=', 'e.id')
            ->where('p.workspace_id', $workspace->id)->where('p.principal_key', $principal->key())
            ->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')->whereNotNull('p.'.$column)
            ->when($search !== '', fn (Builder $query): Builder => $query->whereRaw("e.normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']));
    }

    private function seek(Builder $query, string $column, string $timestamp, int $id, bool $previous): void
    {
        $operator = $previous ? '>' : '<';
        $query->where('p.'.$column, $operator.'=', $timestamp)
            ->where(fn (Builder $part): Builder => $part->where('p.'.$column, $operator, $timestamp)
                ->orWhere(fn (Builder $tie): Builder => $tie->where('p.'.$column, $timestamp)->where('p.entry_id', $operator, $id)));
    }

    /** @return array{list<object>, ?object, ?object, bool} */
    private function scan(Builder $query, object $workspace, Principal $principal, string $column, bool $previous, int $limit): array
    {
        abort_unless($limit >= 1 && $limit <= 120, 422);
        $order = $previous ? 'asc' : 'desc';
        $rows = $query->orderBy('p.'.$column, $order)->orderBy('p.entry_id', $order)->limit($limit + 1)
            ->get([...self::CARD_COLUMNS, 'p.'.$column.' as personal_sort_at']);
        $scanned = $rows->take($limit);

        return [$this->sources->visible($workspace, $principal, $scanned), $scanned->first(), $scanned->last(), $rows->count() > $limit];
    }

    private function normalizeSearch(string $search): string
    {
        abort_if(mb_strlen($search) > 100, 422);

        return mb_strtolower(trim($search), 'UTF-8');
    }

    private function sortColumn(string $view): string
    {
        return match ($view) {
            'starred' => 'starred_at',
            'recent' => 'viewed_at',
            default => abort(422, 'Invalid personal view.'),
        };
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d H:i:s.u');
    }

    private function scope(array $values): string
    {
        return hash('sha256', json_encode(['personal-v1', ...$values], JSON_THROW_ON_ERROR));
    }

    private function encodeCursor(string $scope, object $edge, string $direction, ?string $anchor = null): string
    {
        return Crypt::encryptString(json_encode(['scope' => $scope, 'direction' => $direction, 'anchor' => $anchor,
            'at' => $edge->personal_sort_at, 'id' => (int) $edge->id, 'expires' => now()->timestamp + 3600], JSON_THROW_ON_ERROR));
    }

    /** @return array{at: string, id: int, direction: string, anchor: ?string}|null */
    private function decodeCursor(?string $cursor, string $scope): ?array
    {
        if ($cursor === null) {
            return null;
        }
        abort_if(strlen($cursor) > 4096, 422, 'Invalid personal cursor.');
        try {
            $token = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(422, 'Invalid personal cursor.');
        }
        abort_unless(is_array($token) && ($token['scope'] ?? null) === $scope && ($token['expires'] ?? 0) > now()->timestamp
            && is_string($token['at'] ?? null) && is_int($token['id'] ?? null) && $token['id'] > 0
            && in_array($token['direction'] ?? null, ['next', 'previous'], true), 422, 'Cursor does not match this personal view.');

        return $token;
    }
}
