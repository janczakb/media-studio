<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\BrowseOptions;

final class SearchController
{
    public function index(Request $request, string $workspace): JsonResponse
    {
        return $this->run($request, $workspace);
    }

    public function library(Request $request, string $workspace, BrowseOptions $options): JsonResponse
    {
        return $this->run($request, $workspace, $options);
    }

    private function run(Request $request, string $workspace, ?BrowseOptions $options = null): JsonResponse
    {
        abort_unless(config('media-studio.search.enabled') === true, 404);
        $principal = Principal::fromUser($request->user());
        $scope = app(WorkspaceAccess::class)->resolve($principal, $workspace);
        $data = $request->validate(['mode' => ['nullable', $options === null ? 'in:lexical,hybrid,semantic' : 'in:index,hybrid,semantic'], 'q' => ['required', 'string', 'max:200'], 'cursor' => ['nullable', 'string', 'max:4096']]);
        $mode = $data['mode'] ?? 'lexical';
        $mode = $mode === 'index' ? 'lexical' : $mode;
        abort_unless($mode === 'lexical' || config('media-studio.search.semantic.enabled') === true, 404);
        $query = trim($data['q']);
        abort_if($query === '' || ! mb_check_encoding($query, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $query), 422);
        $fingerprint = hash('sha256', json_encode([$principal->key(), $workspace, $query, $mode, $options?->scope(), $options !== null ? $request->input('kind') : null, config('media-studio.search.semantic.revision', '1'), hash('sha256', json_encode(config('media-studio.search.semantic.embedder'), JSON_THROW_ON_ERROR)), app(Meilisearch::class)->index()], JSON_THROW_ON_ERROR));
        $offset = 0;
        if (isset($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid search cursor.');
            }
            abort_unless(is_array($cursor) && ($cursor['scope'] ?? null) === $fingerprint && ($cursor['expires'] ?? 0) > time() && is_int($cursor['offset'] ?? null), 422);
            $offset = $cursor['offset'];
            abort_if($offset < 0 || $offset >= 1000, 422);
        }
        try {
            $result = app(Meilisearch::class)->request('POST', '/indexes/'.app(Meilisearch::class)->index().'/search', [
                ...($mode === 'lexical' ? [] : ['hybrid' => ['embedder' => 'studio', 'semanticRatio' => $mode === 'semantic' ? 1.0 : 0.5]]),
                'q' => $query, 'filter' => 'workspace = '.json_encode($workspace, JSON_THROW_ON_ERROR),
                'offset' => $offset, 'limit' => 60,
                'attributesToRetrieve' => ['id', 'revision', 'version', 'contentHash', 'aiHash'],
            ]);
        } catch (\Throwable) {
            abort(503, 'Search is temporarily unavailable.');
        }
        $hits = array_slice(is_array($result['hits'] ?? null) ? $result['hits'] : [], 0, 60);
        $ids = array_values(array_filter(array_column($hits, 'id'), static fn ($id): bool => is_string($id) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $id) === 1));
        $records = DB::table('fms_entries')->where('workspace_id', $scope->id)->whereNull('trashed_at')->whereIn('public_id', $ids);
        if ($options !== null) {
            $options->apply($records, $scope, $principal);
            if ($request->filled('kind')) {
                $records->where('kind', $request->input('kind'));
            }
            if (config('media-studio.application_files.enabled') === true) {
                $records->where('source_kind', 'managed');
            }
        }
        $rows = $records->get();
        $visible = collect(app(SourcePolicy::class)->visible($scope, $principal, $rows))->keyBy('public_id');
        $content = app(ContentProjection::class)->forEntries($rows);
        $ai = app(AiProjection::class)->forEntries($rows);
        $items = [];
        foreach ($hits as $hit) {
            if (! is_array($hit) || ! is_string($hit['id'] ?? null)) {
                continue;
            }
            $row = $visible->get($hit['id']);
            if ($row === null || (int) ($hit['revision'] ?? -1) !== (int) $row->revision || ($hit['version'] ?? null) !== $row->current_version_id) {
                continue;
            }
            $projection = $content[$row->id] ?? null;
            $hash = ContentProjection::current($projection, $row) ? $projection->fingerprint : '';
            if (($hit['contentHash'] ?? '') !== $hash || ($hit['aiHash'] ?? '') !== ($ai[$row->id]['hash'] ?? '')) {
                continue;
            }
            if ($options !== null) {
                $items[] = $row;

                continue;
            }
            $items[] = ['id' => $row->public_id, 'name' => $row->name, 'mediaType' => $row->media_type, 'versionId' => $row->current_version_id];
        }
        // Do not expose engine counts: they can include inaccessible/stale entries.
        $next = $offset + count($hits);
        $more = count($hits) === 60 && $next < 1000;

        return response()->json(['items' => $options === null ? $items : app(SearchCards::class)->present($scope, $principal, $items), 'hasMore' => $more, 'nextCursor' => $more ? Crypt::encryptString(json_encode(['scope' => $fingerprint, 'offset' => $next, 'expires' => time() + 900], JSON_THROW_ON_ERROR)) : null, 'rankingMayChange' => true])->header('Cache-Control', 'private, no-store');
    }
}
