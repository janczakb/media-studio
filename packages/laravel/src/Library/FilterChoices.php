<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Cache\StudioCache;
use UseFlex\MediaStudio\Identity\Principal;

final class FilterChoices
{
    public function page(Request $request, object $workspace, Principal $principal): array
    {
        $data = $request->validate([
            'kind' => ['required', 'in:extensions,tags,tagLocales'],
            'search' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'in:'.implode(',', EntryTags::locales())],
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $search = mb_strtolower(trim($data['search'] ?? ''), 'UTF-8');
        $limit = (int) ($data['limit'] ?? 60);
        $kind = $data['kind'];
        $locales = $kind === 'tagLocales' ? EntryTags::locales() : [];
        $catalog = $kind === 'tags' ? [] : app(StudioCache::class)->catalog(
            $kind === 'extensions' ? 'extensions' : 'tag-locales',
            json_encode([$kind, $locales, config('media-studio.browse.extensions', FileCategories::EXTENSIONS), config('media-studio.browse.revision', 1)], JSON_THROW_ON_ERROR),
            fn (): array => $kind === 'extensions' ? FileCategories::extensions() : $locales,
        );
        $scope = hash('sha256', json_encode([$principal->key(), $workspace->public_id, $kind, $search, $data['locale'] ?? null, $catalog], JSON_THROW_ON_ERROR));
        $after = null;
        if (isset($data['cursor'])) {
            try {
                $payload = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid filter options cursor.');
            }
            abort_unless(is_array($payload) && ($payload['scope'] ?? '') === $scope && ($payload['expires'] ?? 0) > time() && is_array($payload['after'] ?? null), 422, 'Filter options cursor does not match this scope.');
            $after = $payload['after'];
        }
        $encode = fn (array $position): string => Crypt::encryptString(json_encode(['scope' => $scope, 'after' => $position, 'expires' => time() + 3600], JSON_THROW_ON_ERROR));
        if ($kind !== 'tags') {
            sort($catalog, SORT_STRING);
            $items = array_values(array_filter($catalog, static fn (string $value): bool => str_starts_with($value, $search) && ($after === null || $value > ($after[0] ?? ''))));
            $hasMore = count($items) > $limit;
            $items = array_slice($items, 0, $limit);

            return ['items' => array_map(static fn (string $value): array => ['id' => $value, 'label' => $kind === 'extensions' ? '.'.$value : $value], $items), 'nextCursor' => $hasMore ? $encode([end($items)]) : null, 'hasMore' => $hasMore];
        }
        $query = DB::table('fms_tags as t')->join('fms_entry_tags as et', 't.id', '=', 'et.tag_id')->join('fms_entries as e', 'e.id', '=', 'et.entry_id')
            ->where('t.workspace_id', $workspace->id)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at');
        if (isset($data['locale'])) {
            $query->where('t.locale', $data['locale']);
        }
        if ($search !== '') {
            $query->whereRaw("t.normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }
        if ($after !== null) {
            abort_unless(count($after) === 3 && is_string($after[0]) && is_int($after[1]) && is_int($after[2]), 422, 'Invalid tag cursor.');
            $query->where(fn ($q) => $q->where('t.normalized_name', '>', $after[0])->orWhere(fn ($q) => $q->where('t.normalized_name', $after[0])->where('t.id', '>', $after[1]))->orWhere(fn ($q) => $q->where('t.normalized_name', $after[0])->where('t.id', $after[1])->where('e.id', '>', $after[2])));
        }
        // Page assignments, not an unbounded aggregate. The client deduplicates tag
        // identities across pages; revoked source records must not reveal labels.
        $rows = $query->orderBy('t.normalized_name')->orderBy('t.id')->orderBy('e.id')->limit($limit + 1)
            ->get(['e.id', 'e.source_kind', 't.id as tag_id', 't.public_id', 't.name', 't.normalized_name']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $visible = app(SourcePolicy::class)->visible($workspace, $principal, $rows->all());
        $items = [];
        foreach ($visible as $row) {
            $items[$row->public_id] = ['id' => $row->public_id, 'label' => $row->name];
        }
        $last = $rows->last();

        return ['items' => array_values($items), 'nextCursor' => $hasMore && $last ? $encode([(string) $last->normalized_name, (int) $last->tag_id, (int) $last->id]) : null, 'hasMore' => $hasMore];
    }
}
