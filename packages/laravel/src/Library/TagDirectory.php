<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Identity\Principal;

final class TagDirectory
{
    public function page(Request $request, object $workspace, Principal $principal): array
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:4096'], 'limit' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $search = mb_strtolower(trim($data['search'] ?? ''), 'UTF-8');
        $scope = hash('sha256', json_encode([$workspace->public_id, $principal->key(), $search], JSON_THROW_ON_ERROR));
        $cursor = null;
        if (isset($data['cursor'])) {
            try {
                $payload = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid tag cursor.');
            }
            abort_unless(is_array($payload) && ($payload['scope'] ?? null) === $scope && ($payload['expires'] ?? 0) > time(), 422, 'Tag cursor does not match this view.');
            $cursor = Cursor::fromEncoded($payload['cursor'] ?? null);
            abort_if($cursor === null, 422, 'Invalid tag cursor.');
        }
        // Bound work by assignments; a tag cannot disclose a source to which the
        // current user has lost access. Empty pages can occur after access filtering.
        $query = DB::table('fms_tags as t')->join('fms_entry_tags as et', 'et.tag_id', '=', 't.id')
            ->join('fms_entries as e', 'e.id', '=', 'et.entry_id')
            ->where('t.workspace_id', $workspace->id)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')
            ->when($search !== '', fn ($q) => $q->whereRaw("t.normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']))
            ->orderBy('tag_name_key')->orderBy('tag_key')->orderBy('entry_key');
        $page = $query->cursorPaginate($data['limit'] ?? 60, ['e.id as entry_key', 'e.source_kind', 't.id as tag_key', 't.public_id', 't.name', 't.locale', 't.normalized_name as tag_name_key'], 'cursor', $cursor);
        $rows = array_map(static fn ($row) => (object) [...(array) $row, 'id' => $row->entry_key], $page->items());
        $visible = app(SourcePolicy::class)->visible($workspace, $principal, $rows);
        $items = [];
        foreach ($visible as $row) {
            $items[$row->public_id] = ['id' => $row->public_id, 'label' => $row->name, 'locale' => $row->locale];
        }
        $encode = fn (?Cursor $position): ?string => $position === null ? null : Crypt::encryptString(json_encode(['scope' => $scope, 'cursor' => $position->encode(), 'expires' => time() + 3600], JSON_THROW_ON_ERROR));

        return ['items' => array_values($items), 'nextCursor' => $encode($page->nextCursor()), 'previousCursor' => $encode($page->previousCursor()), 'hasMore' => $page->hasMorePages()];
    }
}
