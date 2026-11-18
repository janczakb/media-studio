<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Delivery\Delivery;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Spatie\SourceIndexer;
use UseFlex\MediaStudio\Intelligence\ImageInsights;
use UseFlex\MediaStudio\Library\BrowseOptions;
use UseFlex\MediaStudio\Library\BulkEntries;
use UseFlex\MediaStudio\Library\CreateFolder;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\EntryOperations;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Library\FilterChoices;
use UseFlex\MediaStudio\Library\ImageDrawing;
use UseFlex\MediaStudio\Library\MediaReferences;
use UseFlex\MediaStudio\Library\PersonalLibrary;
use UseFlex\MediaStudio\Library\PhotosOptions;
use UseFlex\MediaStudio\Library\ResolveFolders;
use UseFlex\MediaStudio\Library\TagDirectory;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Navigation\FolderNavigation;
use UseFlex\MediaStudio\Navigation\NavigationOperations;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Renditions\Renditions;
use UseFlex\MediaStudio\Search\SearchCards;
use UseFlex\MediaStudio\Search\SearchController;
use UseFlex\MediaStudio\Trash\TreeOperations;
use UseFlex\MediaStudio\Workspaces\CreateWorkspace;
use UseFlex\MediaStudio\Workspaces\DefaultWorkspace;

final class StudioController
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private SourcePolicy $sources, private PersonalLibrary $personal) {}

    public function workspaces(Request $request): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $default = app(DefaultWorkspace::class)->ensure($request->user());
        $rows = $this->access->query($principal)->orderByRaw('CASE WHEN w.id = ? THEN 0 ELSE 1 END', [$default ?? 0])->orderBy('w.id')->limit(100)->get();
        foreach ($rows as $row) {
            $row->is_default = (int) $row->id === $default;
        }

        $limit = max(1, (int) config('media-studio.workspaces.max_owned', 10));
        $owned = $this->access->query($principal)->where('m.role', 'owner')->limit($limit)->get(['w.id'])->count();

        return response()->json(['workspaceIdentity' => true, 'appearance' => ['primaryColor' => config('media-studio.appearance.primary_color', '#0866ff')], 'viewer' => app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class))->resolve($request->user()), 'items' => $rows->map(fn ($w): array => $this->workspaceCard($w)), 'workspacesEnabled' => (bool) config('media-studio.workspaces.enabled', true), 'workspaceLimit' => $limit, 'workspaceCreationAllowed' => config('media-studio.workspaces.enabled', true) && (bool) config('media-studio.workspace_creation') && app(StudioPermissions::class)->allows($request->user(), 'workspaces.create'), 'canCreate' => config('media-studio.workspaces.enabled', true) && $owned < $limit && (bool) config('media-studio.workspace_creation') && app(StudioPermissions::class)->allows($request->user(), 'workspaces.create')]);
    }

    public function workspace(Request $request, string $workspace): JsonResponse
    {
        return response()->json($this->workspaceCard(
            $this->access->resolve(Principal::fromUser($request->user()), $workspace),
        ));
    }

    public function createWorkspace(Request $request, CreateWorkspace $workspaces): JsonResponse
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:15'], 'emoji' => $this->workspaceEmojiRules(), 'kind' => ['sometimes', 'in:personal,team'], 'requestKey' => ['sometimes', 'uuid']]);

        return response()->json($this->workspaceCard($workspaces->execute($request->user(), $data['name'], $data['requestKey'] ?? null, array_intersect_key($data, array_flip(['emoji', 'kind'])))), 201);
    }

    public function updateWorkspace(Request $request, string $workspace): JsonResponse
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:15'], 'emoji' => $this->workspaceEmojiRules()]);
        $name = trim($data['name']);
        abort_if($name === '', 422);
        $w = $this->access->resolve(Principal::fromUser($request->user()), $workspace, write: true);
        abort_unless($w->role === 'owner', 403);
        DB::table('fms_workspaces')->where('id', $w->id)->update(['name' => $name, 'updated_at' => now()] + (array_key_exists('emoji', $data) ? ['emoji' => $data['emoji']] : []));
        $w->name = $name;
        if (array_key_exists('emoji', $data)) {
            $w->emoji = $data['emoji'];
        }

        return response()->json($this->workspaceCard($w));
    }

    public function deleteWorkspace(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.workspaces.enabled', true), 403);
        $request->validate(['confirmName' => ['required', 'string', 'max:160']]);
        DB::transaction(function () use ($request, $workspace): void {
            $w = $this->access->resolve(Principal::fromUser($request->user()), $workspace, write: true, lock: true);
            abort_unless($w->role === 'owner', 403);
            abort_unless(hash_equals($w->name, $request->string('confirmName')->toString()), 422, 'Confirm the current workspace name.');
            abort_if(DB::table('fms_workspace_defaults')->where('workspace_id', $w->id)->exists(), 409, 'Choose a different default workspace first.');
            foreach (['fms_entries', 'fms_upload_sessions', 'fms_upload_receipts', 'fms_source_imports', 'fms_archive_jobs'] as $table) {
                abort_if(DB::table($table)->where('workspace_id', $w->id)->exists(), 409, 'This workspace contains files or processing records. Transfer or clean them before removing the workspace.');
            }
            DB::table('fms_workspaces')->where('id', $w->id)->delete();
        });

        return response()->json(['deleted' => true]);
    }

    public function makeDefaultWorkspace(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.workspaces.enabled', true), 403);
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace, write: true);
        abort_unless($w->role === 'owner', 403);
        app(DefaultWorkspace::class)->select($w, $principal);
        $w->is_default = true;

        return response()->json($this->workspaceCard($w));
    }

    public function undoNavigation(Request $request, string $workspace, string $operation, NavigationOperations $operations): JsonResponse
    {
        return response()->json($operations->undo(Principal::fromUser($request->user()), $workspace, $operation))->header('Cache-Control', 'private, no-store');
    }

    public function navigationChanges(Request $request, string $workspace, FolderNavigation $navigation): JsonResponse
    {
        $data = $request->validate(['after' => ['required', 'integer', 'min:0', 'max:9007199254740991']]);

        return response()->json($navigation->changes(Principal::fromUser($request->user()), $workspace, (int) $data['after']))->header('Cache-Control', 'private, no-store');
    }

    public function folderContext(Request $request, string $workspace, string $entry, FolderNavigation $navigation): JsonResponse
    {
        return response()->json($navigation->context(Principal::fromUser($request->user()), $workspace, $entry))->header('Cache-Control', 'private, no-store');
    }

    public function folderAncestors(Request $request, string $workspace, string $entry, FolderNavigation $navigation): JsonResponse
    {
        $data = $request->validate(['cursor' => ['required', 'string', 'max:4096'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($navigation->ancestors(Principal::fromUser($request->user()), $workspace, $entry, $data['cursor'], (int) ($data['limit'] ?? 50)))->header('Cache-Control', 'private, no-store');
    }

    public function browse(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $data = $request->validate([
            'parent' => ['nullable', 'ulid'], 'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:120'],
            'view' => ['nullable', 'in:files,photos,trash,folders,starred,recent,tagged'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        $view = $data['view'] ?? 'files';
        $options = BrowseOptions::from($request, $view);
        $photos = PhotosOptions::from($request, $view);
        if (in_array($view, ['starred', 'recent'], true)) {
            abort_if(isset($data['parent']), 422, 'Personal views are scoped to the whole workspace.');
            $page = $this->personal->page($principal, $workspace, $view, $data['cursor'] ?? null, (int) ($data['limit'] ?? 60), $data['search'] ?? '');

            return response()->json($this->personalPage($w, $page));
        }
        $parentId = $this->entries->parentId($w->id, $data['parent'] ?? null);
        $search = mb_strtolower(trim($data['search'] ?? ''), 'UTF-8');
        $scope = hash('sha256', json_encode([$principal->type, $principal->id, $workspace, $parentId, $view, $search, $options->scope(), $view === 'photos' ? $photos->scope() : null], JSON_THROW_ON_ERROR));
        $cursor = null;

        if (isset($data['cursor'])) {
            try {
                $payload = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid browse cursor.');
            }

            abort_unless(is_array($payload) && ($payload['scope'] ?? null) === $scope && ($payload['expires'] ?? 0) > time(), 422, 'Cursor does not match this view.');
            $cursor = Cursor::fromEncoded($payload['cursor'] ?? null);
            abort_if($cursor === null, 422, 'Invalid browse cursor.');
        }

        $query = DB::table('fms_entries')->where('workspace_id', $w->id);
        if (config('media-studio.application_files.enabled') === true) {
            $query->where('source_kind', 'managed');
        }
        if ($view !== 'trash' && $w->tree_operation_id !== null) {
            $query->whereNotIn('id', DB::table('fms_tree_operation_nodes')->where('operation_id', $w->tree_operation_id)->whereExists(fn ($q) => $q->selectRaw('1')->from('fms_tree_operations')->whereColumn('fms_tree_operations.id', 'fms_tree_operation_nodes.operation_id')->where('operation', 'trash')->where('state', 'preparing'))->select('entry_id'));
        }
        if ($view === 'trash') {
            $query->whereNotNull('trashed_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_entries as parent')->whereColumn('parent.id', 'fms_entries.parent_id')->whereNotNull('parent.trashed_at'));
        } elseif ($view === 'photos') {
            $photos->apply($query, $w, $principal);
        } elseif ($view === 'tagged') {
            $query->whereNull('trashed_at');
        } else {
            $query->whereNull('trashed_at')->where('parent_scope', $parentId ?? 0);
            if ($view === 'folders') {
                $query->where('kind', 'folder');
            }
        }

        if ($search !== '') {
            $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }

        $options->apply($query, $w, $principal);
        if ($view === 'photos') {
            $photos->order($query);
        } else {
            $options->order($query);
        }
        $page = $query->cursorPaginate($data['limit'] ?? 60, array_unique(['id', 'public_id', 'kind', 'sort_group', 'name', 'normalized_name', 'media_type', 'mime_type', 'note_excerpt', 'note_background', 'audio_waveform', 'audio_waveform_version', 'size_bytes', 'current_version_id', 'created_at', 'updated_at', 'photo_created', 'revision', 'trashed_at', 'width', 'height', 'thumbnail_state', 'thumbnail_version_id', 'thumbnail_key', 'preview_state', 'preview_version_id', 'preview_key', 'source_kind', $options->column()]), 'cursor', $cursor);
        if ($view === 'folders') {
            $folderIds = array_map(static fn (object $row): int => (int) $row->id, $page->items());
            $parentsWithChildren = $folderIds === [] ? [] : DB::table('fms_entries as parents')
                ->where('parents.workspace_id', $w->id)->whereIn('parents.id', $folderIds)
                ->whereExists(function ($children) use ($w): void {
                    $children->selectRaw('1')->from('fms_entries as children')
                        ->where('children.workspace_id', $w->id)
                        ->whereColumn('children.parent_scope', 'parents.id')
                        ->whereNull('children.trashed_at')->where('children.sort_group', 0)
                        ->where('children.kind', 'folder')->where('children.source_kind', 'managed');
                })->pluck('parents.id')->all();
            $parentsWithChildren = array_fill_keys($parentsWithChildren, true);
            foreach ($page->items() as $row) {
                $row->has_child_folders = isset($parentsWithChildren[$row->id]);
            }
        }
        $next = $page->nextCursor()?->encode();
        $previous = $page->previousCursor()?->encode();
        $encode = fn (?string $value): ?string => $value === null ? null : Crypt::encryptString(json_encode(['scope' => $scope, 'cursor' => $value, 'expires' => time() + 3600], JSON_THROW_ON_ERROR));

        return response()->json([
            'items' => $this->visibleCards($w, $principal, $page->items()),
            'nextCursor' => $encode($next),
            'previousCursor' => $encode($previous),
            'hasMore' => $page->hasMorePages(),
            'canWrite' => in_array($w->role, ['owner', 'admin', 'editor'], true) && app(StudioPermissions::class)->allows(request()->user(), 'write', $w),
        ]);
    }

    public function librarySearch(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $scope = $this->access->resolve($principal, $workspace);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'in:file,folder'],
            'mode' => ['nullable', 'in:exact,index,hybrid,semantic'],
            'cursor' => ['nullable', 'string', 'max:4096'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $term = trim($data['q'] ?? '');
        $options = BrowseOptions::from($request, 'files');
        if (($data['mode'] ?? 'exact') !== 'exact') {
            return app(SearchController::class)->library($request, $workspace, $options);
        }
        $kind = $data['kind'] ?? null;
        $fingerprint = hash('sha256', json_encode([$principal->key(), $workspace, $term, $kind, $options->scope()], JSON_THROW_ON_ERROR));
        $cursor = null;
        if (isset($data['cursor'])) {
            try {
                $decoded = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid search cursor.');
            }
            abort_unless(is_array($decoded) && ($decoded['scope'] ?? null) === $fingerprint && ($decoded['expires'] ?? 0) > time(), 422);
            $cursor = Cursor::fromEncoded($decoded['cursor'] ?? null);
            abort_if($cursor === null, 422, 'Invalid search cursor.');
        }

        $query = DB::table('fms_entries')->where('workspace_id', $scope->id)->whereNull('trashed_at');
        if (config('media-studio.application_files.enabled') === true) {
            $query->where('source_kind', 'managed');
        }
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        if ($term !== '') {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term, 'UTF-8')).'%';
            if (str_starts_with($term, '#') && strlen($term) > 1) {
                $query->whereIn('fms_entries.id', fn ($tags) => $tags->select('entry_id')->from('fms_entry_tags')
                    ->join('fms_tags', 'fms_tags.id', '=', 'tag_id')->where('fms_tags.workspace_id', $scope->id)
                    ->whereRaw("fms_tags.normalized_name LIKE ? ESCAPE '!'", [substr($prefix, 1)]));
            } else {
                $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$prefix]);
            }
        }
        $options->apply($query, $scope, $principal);
        if ($term === '') {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            $query->orderBy('normalized_name')->orderBy('id');
        }
        $page = $query->cursorPaginate((int) ($data['limit'] ?? 36), SearchCards::COLUMNS, 'cursor', $cursor);
        $next = $page->nextCursor()?->encode();

        return response()->json([
            'items' => app(SearchCards::class)->present($scope, $principal, $this->sources->visible($scope, $principal, $page->items())),
            'hasMore' => $next !== null,
            'nextCursor' => $next === null ? null : Crypt::encryptString(json_encode([
                'scope' => $fingerprint, 'cursor' => $next, 'expires' => time() + 900,
            ], JSON_THROW_ON_ERROR)),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function filterOptions(Request $request, string $workspace, FilterChoices $choices): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);

        return response()->json($choices->page($request, $w, $principal));
    }

    public function tagDirectory(Request $request, string $workspace, TagDirectory $tags): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);

        return response()->json($tags->page($request, $resolved, $principal));
    }

    public function replaceTags(Request $request, string $workspace, string $entry, EntryTags $tags): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'tags' => ['present', 'array', 'max:32']]);

        return response()->json($tags->replace(Principal::fromUser($request->user()), $workspace, $entry, (int) $data['revision'], $data['tags']));
    }

    public function references(Request $request, string $workspace, MediaReferences $references): JsonResponse
    {
        $data = $request->validate(['references' => ['present', 'array', 'max:120'], 'references.*' => ['required', 'array:assetId,versionId,rendition'], 'references.*.assetId' => ['required', 'ulid'], 'references.*.versionId' => ['nullable', 'ulid'], 'references.*.rendition' => ['nullable', 'string', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/']]);

        return response()->json(['items' => $references->resolve(Principal::fromUser($request->user()), $workspace, $data['references'])]);
    }

    public function syncSpatie(Request $request, string $workspace, string $mount, SourceIndexer $indexer): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:4096'], 'limit' => ['nullable', 'integer', 'min:1', 'max:120']]);

        return response()->json($indexer->syncPage($principal, $workspace, $mount, $data['cursor'] ?? null, (int) ($data['limit'] ?? 60)));
    }

    public function status(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $data = $request->validate(['ids' => ['required', 'array', 'max:120'], 'ids.*' => ['required', 'ulid']]);
        $rows = DB::table('fms_entries')->where('workspace_id', $w->id)->whereIn('public_id', $data['ids'])->whereNull('trashed_at')
            ->get(['id', 'public_id', 'kind', 'name', 'media_type', 'mime_type', 'note_excerpt', 'note_background', 'audio_waveform', 'audio_waveform_version', 'size_bytes', 'current_version_id', 'created_at', 'updated_at', 'photo_created', 'revision', 'trashed_at', 'width', 'height', 'thumbnail_state', 'thumbnail_version_id', 'thumbnail_key', 'preview_state', 'preview_version_id', 'preview_key', 'source_kind']);

        $refreshing = DB::table('fms_renditions')->whereIn('version_id', $rows->pluck('current_version_id')->filter())
            ->whereNotNull('replacement_recipe')->whereIn('state', ['queued', 'processing'])->distinct()->pluck('version_id')->flip();

        return response()->json(['items' => array_map(
            fn (array $item): array => [...$item, 'renditionsRefreshing' => $refreshing->has($item['versionId'] ?? '')],
            $this->visibleCards($w, $principal, $rows),
        )]);
    }

    public function neighbors(Request $request, string $workspace, string $entry): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $data = $request->validate([
            'parent' => ['nullable', 'ulid'], 'view' => ['nullable', 'in:files,photos,starred,recent'],
            'search' => ['nullable', 'string', 'max:100'], 'direction' => ['required', 'in:next,previous'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:120'], 'cursor' => ['nullable', 'string', 'max:4096'],
        ]);
        $view = $data['view'] ?? 'files';
        $options = BrowseOptions::from($request, $view, neighbors: true);
        $photos = PhotosOptions::from($request, $view);
        if (in_array($view, ['starred', 'recent'], true)) {
            abort_if(isset($data['parent']), 422, 'Personal views are scoped to the whole workspace.');
            $page = $this->personal->neighbors($principal, $workspace, $entry, $view, $data['search'] ?? '', $data['direction'], $data['cursor'] ?? null, (int) ($data['limit'] ?? 60));

            return response()->json($this->personalPage($w, $page));
        }
        $parentId = $this->entries->parentId($w->id, $data['parent'] ?? null);
        $query = DB::table('fms_entries')->where('workspace_id', $w->id)->where('kind', 'file')->where('sort_group', 1)->whereNull('trashed_at');
        if (config('media-studio.application_files.enabled') === true) {
            $query->where('source_kind', 'managed');
        }
        if ($view === 'photos') {
            $photos->apply($query, $w, $principal);
        } else {
            $query->where('parent_scope', $parentId ?? 0);
        }
        $search = mb_strtolower(trim($data['search'] ?? ''), 'UTF-8');
        if ($search !== '') {
            $query->whereRaw("normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }
        $options->apply($query, $w, $principal);
        $column = $view === 'photos' ? 'photo_created' : $options->column();
        $anchor = (clone $query)->where('public_id', $entry)->first(['id', 'source_kind', $column]);
        abort_if($anchor === null, 404);
        $this->sources->readable($w, $principal, $anchor);

        $next = $data['direction'] === 'next';
        $ascending = $next === (($view === 'photos' ? 'desc' : $options->direction) === 'asc');
        $comparison = $ascending ? '>' : '<';
        $order = $ascending ? 'asc' : 'desc';
        $scope = hash('sha256', json_encode(['neighbors', $principal->key(), $workspace, $parentId, $view, $search, $data['direction'], $options->scope(), $view === 'photos' ? $photos->scope() : null], JSON_THROW_ON_ERROR));
        $value = $anchor->{$column};
        $id = $anchor->id;
        if (isset($data['cursor'])) {
            try {
                $payload = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid preview cursor.');
            }
            abort_unless(is_array($payload) && ($payload['scope'] ?? null) === $scope
                && ($payload['anchor'] ?? null) === $entry && ($payload['expires'] ?? 0) > time(), 422, 'Cursor does not match this preview.');
            $value = $payload['value'];
            $id = $payload['id'];
        }
        $query->where(function ($q) use ($column, $value, $id, $comparison): void {
            $q->where($column, $comparison, $value)
                ->orWhere(fn ($tie) => $tie->where($column, $value)->where('id', $comparison, $id));
        });
        $limit = (int) ($data['limit'] ?? 60);
        $rows = $query->orderBy($column, $order)->orderBy('id', $order)->limit($limit + 1)
            ->get(array_unique(['id', 'public_id', 'kind', 'name', 'normalized_name', 'media_type', 'mime_type', 'note_excerpt', 'note_background', 'audio_waveform', 'audio_waveform_version', 'size_bytes', 'current_version_id', 'created_at', 'updated_at', 'photo_created', 'revision', 'trashed_at', 'width', 'height', 'thumbnail_state', 'thumbnail_version_id', 'thumbnail_key', 'preview_state', 'preview_version_id', 'preview_key', 'source_kind', $column]));
        $hasMore = $rows->count() > $limit;
        $scanned = $rows->take($limit);
        $items = $this->sources->visible($w, $principal, $scanned);
        $visibleEdge = last($items);
        $scannedEdge = $scanned->last();
        $cursor = null;
        if ($hasMore && $scannedEdge->id !== ($visibleEdge->id ?? null)) {
            $cursor = Crypt::encryptString(json_encode([
                'scope' => $scope, 'anchor' => $visibleEdge->public_id ?? $entry,
                'value' => $scannedEdge->{$column}, 'id' => $scannedEdge->id, 'expires' => time() + 3600,
            ], JSON_THROW_ON_ERROR));
        }

        return response()->json([
            'items' => $this->cardsWithPersonalState($w, $principal, $next ? $items : array_reverse($items)),
            'nextCursor' => $next ? $cursor : null, 'previousCursor' => $next ? null : $cursor,
            'hasMore' => $hasMore, 'canWrite' => in_array($w->role, ['owner', 'admin', 'editor'], true) && app(StudioPermissions::class)->allows(request()->user(), 'write', $w),
        ]);
    }

    public function resolveFolders(Request $request, string $workspace, ResolveFolders $folders): JsonResponse
    {
        $data = $request->validate(['parent' => ['nullable', 'ulid'], 'paths' => ['required', 'array', 'max:100'], 'paths.*' => ['required', 'string', 'max:4096']]);
        $items = $folders->execute(Principal::fromUser($request->user()), $workspace, $data['parent'] ?? null, $data['paths']);

        return response()->json(['items' => $items]);
    }

    public function createFolder(Request $request, string $workspace, CreateFolder $folders): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'parent' => ['nullable', 'ulid']]);
        $row = $folders->execute(Principal::fromUser($request->user()), $workspace, $data['name'], $data['parent'] ?? null);

        return response()->json($this->entryCard((object) ['public_id' => $workspace], $row), 201);
    }

    public function upload(Request $request, string $workspace, UploadAsset $uploads): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace, write: true);
        $data = $request->validate([
            'parent' => ['nullable', 'ulid'],
            'file' => ['required', 'file', 'max:'.config('media-studio.upload.max_kilobytes')],
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/', $key), 422, 'A valid Idempotency-Key is required.');
        $parentId = $this->entries->parentId($w->id, $data['parent'] ?? null);
        $entry = $uploads->execute($w, $principal, $parentId, $data['file'], $key);

        return response()->json($this->entryCard($w, $entry), 201);
    }

    public function content(Request $request, string $workspace, string $entry, ?string $version = null): Response
    {
        return app(FileContent::class)->send($request, $workspace, $entry, $version, $request->boolean('download'));
    }

    public function thumbnail(Request $request, string $workspace, string $entry): Response
    {
        return $this->display($request, $workspace, $entry, 'thumbnail');
    }

    public function preview(Request $request, string $workspace, string $entry): Response
    {
        return $this->display($request, $workspace, $entry, 'preview');
    }

    public function rendition(Request $request, string $workspace, string $entry, string $version, string $preset): Response
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $row = $this->entries->resolve($w->id, $entry);
        abort_unless($row->trashed_at === null && $row->current_version_id === $version, 404);
        $this->sources->readable($w, $principal, $row);
        $rendition = Renditions::published()->where('entry_id', $row->id)->where('version_id', $version)
            ->where('preset', $preset)->first();
        abort_if($rendition === null || ! in_array($rendition->mime_type, Renditions::IMAGE_MIMES, true), 404);
        app(Renditions::class)->assertExternal($w, $row, $rendition, $principal);

        $record = (object) [
            'disk' => $rendition->disk, 'object_key' => $rendition->object_key, 'mime_type' => $rendition->mime_type,
            'size_bytes' => $rendition->size_bytes, 'name' => $row->name,
        ];

        return app(MediaResponse::class)->stream($request, $record, DeliveryResource::fromRecord($record, $workspace, $version, 'rendition', $preset, $request->boolean('download')));
    }

    private function display(Request $request, string $workspace, string $entry, string $role): Response
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $row = $this->entries->resolve($w->id, $entry);
        abort_unless($row->trashed_at === null && $row->{$role.'_state'} === 'ready' && $row->{$role.'_version_id'} === $row->current_version_id, 404);
        abort_if($request->filled('v') && $request->query('v') !== $row->current_version_id, 404);
        $this->sources->readable($w, $principal, $row);
        $mime = $role === 'thumbnail' ? $this->sources->thumbnailMime($w, $principal, $row) : $this->sources->previewMime($w, $principal, $row);
        $disk = $row->{$role.'_disk'};
        $key = $row->{$role.'_key'};
        if (in_array($row->source_kind, ['managed', 'disk'], true) && $row->{$role.'_preset'} !== null) {
            $rendition = Renditions::published()->where('entry_id', $row->id)->where('version_id', $row->current_version_id)
                ->where('preset', $row->{$role.'_preset'})->first();
            abort_if($rendition === null, 404);
            app(Renditions::class)->assertExternal($w, $row, $rendition, $principal);
            $disk = $rendition->disk;
            $key = $rendition->object_key;
            $mime = $rendition->mime_type;
            if ($role === 'preview' && $row->media_type === 'video' && $mime === 'video/mp4') {
                abort_unless($rendition->owned && $rendition->mime_type === 'video/mp4', 404);

                $record = (object) [
                    'disk' => $rendition->disk, 'object_key' => $rendition->object_key, 'mime_type' => $rendition->mime_type,
                    'size_bytes' => $rendition->size_bytes, 'name' => $row->name,
                ];

                return app(MediaResponse::class)->stream($request, $record, DeliveryResource::fromRecord($record, $workspace, $row->current_version_id, 'video', $row->{$role.'_preset'}));
            }
        }
        abort_unless(in_array($mime, Renditions::IMAGE_MIMES, true), 404);
        $resource = new DeliveryResource($disk, $key, $mime, $row->name, $workspace, $row->current_version_id, $role, $row->{$role.'_preset'});
        if (($redirect = app(Delivery::class)->redirect($resource)) !== null) {
            return $redirect;
        }
        $stream = Storage::disk($disk)->readStream($key);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function entryCard(object $workspace, object $entry): array
    {
        $entry->workspace_public_id = $workspace->public_id;

        return $this->entries->card($entry);
    }

    /** @return list<array<string, mixed>> */
    private function visibleCards(object $workspace, Principal $principal, iterable $rows): array
    {
        return $this->cardsWithPersonalState($workspace, $principal, $this->sources->visible($workspace, $principal, $rows));
    }

    /** @param list<object> $rows @return list<array<string, mixed>> */
    private function cardsWithPersonalState(object $workspace, Principal $principal, array $rows): array
    {
        $states = $this->personal->hydrate($principal, $workspace, $rows);

        $cards = array_map(fn (object $row): array => [
            ...$this->entryCard($workspace, $row),
            'starred' => $states[$row->public_id]['starred'] ?? false,
        ], $rows);
        $thumbnail = request()->validate(['thumbnailPreset' => ['nullable', 'string', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/']]);

        return app(\UseFlex\MediaStudio\Library\ThumbnailProjection::class)->apply($workspace->public_id, $cards, $thumbnail['thumbnailPreset'] ?? null);
    }

    /** @param array{items: list<object>, nextCursor: ?string, previousCursor?: ?string, hasMore: bool} $page */
    private function personalPage(object $workspace, array $page): array
    {
        return [
            'items' => array_map(fn (object $row): array => [
                ...$this->entryCard($workspace, $row),
                'starred' => $row->personal_starred_at !== null,
            ], $page['items']),
            'nextCursor' => $page['nextCursor'],
            'previousCursor' => $page['previousCursor'] ?? null,
            'hasMore' => $page['hasMore'],
            'canWrite' => in_array($workspace->role, ['owner', 'admin', 'editor'], true) && app(StudioPermissions::class)->allows(request()->user(), 'write', $workspace),
        ];
    }

    public function details(Request $request, string $workspace, string $entry, EntryOperations $operations): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = $this->access->resolve($principal, $workspace);
        $row = $this->entries->resolve($w->id, $entry);
        $this->sources->readable($w, $principal, $row);
        $states = $this->personal->hydrate($principal, $w, [$row]);

        $renditions = $row->current_version_id === null ? [] : Renditions::published()
            ->where('entry_id', $row->id)->where('version_id', $row->current_version_id)
            ->orderBy('preset')->limit(64)->get(['preset', 'mime_type', 'size_bytes', 'width', 'height'])->map(
                fn (object $variant): array => [
                    'name' => $variant->preset,
                    'mimeType' => $variant->mime_type,
                    'bytes' => (int) $variant->size_bytes,
                    'width' => $variant->width,
                    'height' => $variant->height,
                ],
            )->all();

        $details = $operations->details($w, $entry);
        $details['tags'] = app(EntryTags::class)->forEntry((int) $row->id);
        $permissions = app(StudioPermissions::class);
        $actor = $request->user();
        $details['canEditTags'] = $row->source_kind === 'managed' && $details['trashedAt'] === null
            && in_array($w->role, ['owner', 'admin', 'editor'], true)
            && $permissions->allows($actor, 'write', $w) && $permissions->allows($actor, 'tags.manage', $w);
        if ($row->media_type === 'image' && $details['versionId'] === $row->current_version_id && $details['trashedAt'] === null
            && $permissions->allows($actor, 'ai.view', $w)) {
            $insights = app(ImageInsights::class)->forEntry($row);
            if ($insights !== []) {
                $details['imageInsights'] = $insights;
            }
        }

        return response()->json([...$details, 'starred' => $states[$entry]['starred'] ?? false, 'renditions' => $renditions])
            ->header('Cache-Control', 'private, no-store');
    }

    public function optimize(Request $request, string $workspace, string $entry, ThumbnailProcessor $processor): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'replace' => ['sometimes', 'boolean'],
            'retention' => ['sometimes', 'in:keep,prune'],
        ]);
        $row = DB::transaction(function () use ($principal, $workspace, $entry, $data, $processor): object {
            $resolved = $this->access->resolve($principal, $workspace, write: true, lock: true);
            $row = $this->entries->resolve($resolved->id, $entry);
            $this->sources->managed($row);
            abort_if($row->trashed_at !== null, 404);
            abort_unless((int) $row->revision === (int) $data['revision'], 409, 'This item changed. Refresh before optimizing.');
            abort_unless($processor->supports($row), 422, 'Image conversion is unavailable for this file.');
            $processor->schedule($resolved, $row, $principal, replace: ($data['replace'] ?? false) ? ($data['retention'] ?? 'keep') : null);

            return $row;
        }, 3);

        $fresh = DB::table('fms_entries')->where('id', $row->id)->first();
        abort_if($fresh === null, 404);
        $refreshing = DB::table('fms_renditions')->where('version_id', $fresh->current_version_id)
            ->whereNotNull('replacement_recipe')->whereIn('state', ['queued', 'processing'])->exists();

        return response()->json([...$this->entryCard((object) ['public_id' => $workspace], $fresh), 'renditionsRefreshing' => $refreshing]);
    }

    public function star(Request $request, string $workspace, string $entry): JsonResponse
    {
        $data = $request->validate(['starred' => ['required', 'boolean']]);

        return response()->json($this->personal->setStar(Principal::fromUser($request->user()), $workspace, $entry, (bool) $data['starred']));
    }

    public function visit(Request $request, string $workspace, string $entry): JsonResponse
    {
        $this->personal->recordView(Principal::fromUser($request->user()), $workspace, $entry);

        return response()->json(null, 204);
    }

    public function bulk(Request $request, string $workspace, BulkEntries $bulk): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'in:move,trash,restore'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.BulkEntries::MAX_ITEMS],
            'items.*' => ['required', 'array:id,revision'],
            'items.*.id' => ['required', 'ulid', 'distinct:ignore_case'],
            'items.*.revision' => ['required', 'integer', 'min:1'],
            'requestId' => ['sometimes', 'string', 'min:16', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'parent' => ['present_if:operation,move', 'prohibited_unless:operation,move', 'nullable', 'ulid'],
        ]);
        $items = array_map(fn (array $item): array => [
            'id' => $item['id'], 'revision' => (int) $item['revision'],
        ], $data['items']);
        $principal = Principal::fromUser($request->user());
        if ($data['operation'] !== 'move' && app(TreeOperations::class)->required($workspace, $items)) {
            return response()->json(app(TreeOperations::class)->admit($principal, $workspace, $data['operation'], $items, $data['requestId'] ?? (string) Str::uuid()), 202);
        }
        $write = function () use ($bulk, $principal, $workspace, $data, $items): array {
            $rows = $bulk->execute($principal, $workspace, $data['operation'], $items, $data['parent'] ?? null);
            $w = (object) ['public_id' => $workspace];

            return ['items' => array_map(fn (object $row): array => $this->entryCard($w, $row), $rows)];
        };
        $result = isset($data['requestId']) && $data['operation'] === 'move'
            ? app(NavigationOperations::class)->run($principal, $workspace, $data['requestId'], $data, array_column($items, 'id'), $write)
            : $write();

        return response()->json($result);
    }

    public function update(Request $request, string $workspace, string $entry, EntryOperations $operations): JsonResponse
    {
        $data = $request->validate([
            'requestId' => ['sometimes', 'string', 'min:16', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'parent' => ['sometimes', 'nullable', 'ulid'],
            'drawing' => ['sometimes', new ImageDrawing],
            'metadata' => ['sometimes', 'array:alt,caption,description'],
            'metadata.alt' => ['nullable', 'string', 'max:500'],
            'metadata.caption' => ['nullable', 'string', 'max:2000'],
            'metadata.description' => ['nullable', 'string', 'max:10000'],
        ]);
        $principal = Principal::fromUser($request->user());
        $command = $data;
        unset($data['requestId']);
        $write = function () use ($operations, $principal, $workspace, $entry, $data): array {
            $row = $operations->execute($principal, $workspace, $entry, 'update', $data);

            return ['items' => [$this->entryCard((object) ['public_id' => $workspace], $row)]];
        };
        if (isset($command['requestId']) && array_diff(array_keys($data), ['revision', 'name', 'parent']) === []) {
            $result = app(NavigationOperations::class)->run($principal, $workspace, $command['requestId'], ['entry' => $entry, ...$command], [$entry], $write);

            return response()->json([...$result['items'][0], 'operationId' => $result['operationId'], 'undoAvailable' => $result['undoAvailable']]);
        }

        return response()->json($write()['items'][0]);
    }

    public function trash(Request $request, string $workspace, string $entry, EntryOperations $operations): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'requestId' => ['sometimes', 'string', 'min:16', 'max:64']]);
        $items = [['id' => $entry, 'revision' => (int) $data['revision']]];
        if (app(TreeOperations::class)->required($workspace, $items)) {
            $kind = $request->route()->getActionMethod() === 'restore' ? 'restore' : 'trash';

            return response()->json(app(TreeOperations::class)->admit(Principal::fromUser($request->user()), $workspace, $kind, $items, $data['requestId'] ?? (string) Str::uuid()), 202);
        }
        $row = $operations->execute(Principal::fromUser($request->user()), $workspace, $entry, 'trash', $data);

        return response()->json($this->entryCard((object) ['public_id' => $workspace], $row));
    }

    public function restore(Request $request, string $workspace, string $entry, EntryOperations $operations): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'requestId' => ['sometimes', 'string', 'min:16', 'max:64']]);
        $items = [['id' => $entry, 'revision' => (int) $data['revision']]];
        if (app(TreeOperations::class)->required($workspace, $items)) {
            $kind = $request->route()->getActionMethod() === 'restore' ? 'restore' : 'trash';

            return response()->json(app(TreeOperations::class)->admit(Principal::fromUser($request->user()), $workspace, $kind, $items, $data['requestId'] ?? (string) Str::uuid()), 202);
        }
        $row = $operations->execute(Principal::fromUser($request->user()), $workspace, $entry, 'restore', $data);

        return response()->json($this->entryCard((object) ['public_id' => $workspace], $row));
    }

    public function treeOperation(Request $request, string $workspace, string $operation): JsonResponse
    {
        return response()->json(app(TreeOperations::class)->status(Principal::fromUser($request->user()), $workspace, $operation));
    }

    public function advanceTreeOperation(Request $request, string $workspace, string $operation): JsonResponse
    {
        return response()->json(app(TreeOperations::class)->advance(Principal::fromUser($request->user()), $workspace, $operation));
    }

    public function cancelTreeOperation(Request $request, string $workspace, string $operation): JsonResponse
    {
        return response()->json(app(TreeOperations::class)->cancel(Principal::fromUser($request->user()), $workspace, $operation));
    }

    /** @return array{id: string, name: string, role: string} */
    private function workspaceEmojiRules(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A(?=[\s\S]*(?:\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|\x{20E3}))\X\z/u'];
    }

    private function workspaceCard(object $w): array
    {
        $permissions = [];
        foreach (StudioPermissions::OPERATIONS as $operation) {
            $permissions[$operation] = ! (($w->kind ?? 'team') === 'personal' && in_array($operation, ['members.invite', 'members.manage'], true)) && app(StudioPermissions::class)->allows(request()->user(), $operation, $w);
        }

        return ['emoji' => $w->emoji ?? null, 'workspaceType' => $w->kind ?? 'team', 'commentsEnabled' => (bool) config('media-studio.comments.enabled', true), 'isDefault' => (bool) ($w->is_default ?? false), 'permissions' => $permissions, 'id' => $w->public_id, 'name' => $w->name, 'role' => $w->role, 'updatedAt' => isset($w->updated_at) ? (string) $w->updated_at : null, 'indexedSearch' => config('media-studio.search.enabled') === true, 'semanticSearch' => config('media-studio.search.enabled') === true && config('media-studio.search.semantic.enabled') === true, 'backgroundArchives' => (bool) config('media-studio.downloads.background_enabled', false), 'guestUploads' => (bool) config('media-studio.sharing.guest_upload_enabled', false)];
    }
}
