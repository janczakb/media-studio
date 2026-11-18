<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Http\MediaResponse;
use UseFlex\MediaStudio\Identity\Principal;

final class ApplicationFilesController
{
    public function sources(Request $request): JsonResponse
    {
        $enabled = config('media-studio.application_files.enabled') === true && Schema::hasTable('fms_application_entries');
        $items = [];
        if ($enabled) {
            foreach ($this->mounts($request) as $source) {
                $items[] = ['id' => $source->key, 'name' => (string) ($source->config['label'] ?? $source->key), 'type' => $source->config['type'], 'readOnly' => true];
            }
        }

        return $this->json(['enabled' => $enabled, 'canDownload' => $this->canDownload($request), 'items' => $items]);
    }

    public function index(Request $request, string $source, ApplicationCatalog $catalog): JsonResponse
    {
        return $this->listing($request, $catalog, $source);
    }

    public function catalog(Request $request, ApplicationCatalog $catalog): JsonResponse
    {
        $request->validate(['source' => ['nullable', 'string', 'regex:/\A[a-z0-9_-]{1,64}\z/']]);

        return $this->listing($request, $catalog, $request->input('source'));
    }

    private function listing(Request $request, ApplicationCatalog $catalog, ?string $filter): JsonResponse
    {
        $values = $request->validate(['parent' => ['nullable', 'ulid'], 'cursor' => ['nullable', 'string', 'max:4096'], 'limit' => ['nullable', 'integer', 'between:1,120'], 'search' => ['nullable', 'string', 'max:100']]);
        $mounts = $this->mounts($request, $filter);
        $principal = Principal::fromUser($request->user());
        $query = $this->query($mounts);
        $parent = isset($values['parent']) ? (clone $query)->where('public_id', $values['parent'])->where('kind', 'folder')->first() : null;
        abort_if(isset($values['parent']) && $parent === null, 404);
        $search = trim($values['search'] ?? '');
        $cursor = $this->cursor($request, $mounts, [$filter, $parent?->public_id, $search]);
        $query->where('parent_key', $parent?->identity ?? hash('sha256', 'root'));
        if ($parent !== null) {
            $query->where('source_key', $parent->source_key);
        }
        $this->search($query, $search);
        $page = $query->orderBy('sort_name')->orderBy('id')->cursorPaginate($values['limit'] ?? 60, ['*'], 'cursor', $cursor->decode($values['cursor'] ?? null));
        $rows = $this->visible($catalog, $mounts, $principal, $page->items());
        $download = $this->canDownload($request);

        return $this->json(['items' => $this->cards($rows, $mounts, $download),
            'nextCursor' => $cursor->encode($page->nextCursor()), 'previousCursor' => $cursor->encode($page->previousCursor()),
            'hasMore' => $page->hasMorePages(), 'canWrite' => false, 'readOnly' => true]);
    }

    public function details(Request $request, string $source, string $entry, ApplicationCatalog $catalog): JsonResponse
    {
        return $this->detailResponse($request, $entry, $catalog, $source);
    }

    public function catalogDetails(Request $request, string $entry, ApplicationCatalog $catalog): JsonResponse
    {
        return $this->detailResponse($request, $entry, $catalog);
    }

    private function detailResponse(Request $request, string $entry, ApplicationCatalog $catalog, ?string $filter = null): JsonResponse
    {
        $mounts = $this->mounts($request, $filter);
        $row = $this->query($mounts)->where('public_id', $entry)->first();
        abort_if($row === null || $this->visible($catalog, $mounts, Principal::fromUser($request->user()), [$row]) === [], 404);
        $parent = $this->query($mounts)->where('source_key', $row->source_key)->where('identity', $row->parent_key)->where('kind', 'folder')->first();

        return $this->json([...$this->cards([$row], $mounts, $this->canDownload($request))[0], 'parentId' => $parent?->public_id, 'parentName' => $parent?->name]);
    }

    public function neighbors(Request $request, string $entry, ApplicationCatalog $catalog): JsonResponse
    {
        $values = $request->validate(['direction' => ['required', 'in:next,previous'], 'cursor' => ['nullable', 'string', 'max:4096'], 'search' => ['nullable', 'string', 'max:100'], 'source' => ['nullable', 'string', 'regex:/\A[a-z0-9_-]{1,64}\z/']]);
        $mounts = $this->mounts($request, $values['source'] ?? null);
        $principal = Principal::fromUser($request->user());
        $anchor = $this->query($mounts)->where('public_id', $entry)->where('kind', 'file')->first();
        abort_if($anchor === null || $this->visible($catalog, $mounts, $principal, [$anchor]) === [], 404);
        $direction = $values['direction'];
        $search = trim($values['search'] ?? '');
        $cursor = $this->cursor($request, $mounts, [$anchor->parent_key === hash('sha256', 'root') ? null : $anchor->source_key, $anchor->parent_key, $direction, $search, $values['source'] ?? null]);
        $query = $this->query($mounts)->where('parent_key', $anchor->parent_key)->where('kind', 'file');
        if ($anchor->parent_key !== hash('sha256', 'root')) {
            $query->where('source_key', $anchor->source_key);
        }
        if (! isset($values['cursor'])) {
            $operator = $direction === 'next' ? '>' : '<';
            $query->where(fn ($q) => $q->where('sort_name', $operator, $anchor->sort_name)->orWhere(fn ($same) => $same->where('sort_name', $anchor->sort_name)->where('id', $operator, $anchor->id)));
        }
        $this->search($query, $search);
        $page = $query->orderBy('sort_name', $direction === 'next' ? 'asc' : 'desc')->orderBy('id', $direction === 'next' ? 'asc' : 'desc')->cursorPaginate(60, ['*'], 'cursor', $cursor->decode($values['cursor'] ?? null));
        $rows = $this->visible($catalog, $mounts, $principal, $page->items());
        if ($direction === 'previous') {
            $rows = array_reverse($rows);
        }
        $continuation = $cursor->encode($page->nextCursor());
        $download = $this->canDownload($request);

        return $this->json(['items' => $this->cards($rows, $mounts, $download),
            'nextCursor' => $direction === 'next' ? $continuation : null, 'previousCursor' => $direction === 'previous' ? $continuation : null,
            'hasMore' => $page->hasMorePages(), 'canWrite' => false]);
    }

    public function thumbnailStatus(Request $request, ApplicationCatalog $catalog, ApplicationThumbnails $thumbnails): JsonResponse
    {
        $values = $request->validate(['ids' => ['required', 'array', 'list', 'max:120'], 'ids.*' => ['required', 'ulid', 'distinct']]);
        $profile = ApplicationThumbnailProfile::configured();
        if ($profile === null) {
            return $this->json(['items' => []]);
        }
        $mounts = $this->mounts($request);
        $principal = Principal::fromUser($request->user());
        $rows = $this->visible($catalog, $mounts, $principal, $this->query($mounts)->whereIn('public_id', $values['ids'])->get()->all());
        $keys = $thumbnails->keys($profile, $rows, $mounts);
        $states = $thumbnails->states($keys);
        $missing = array_diff($keys, array_keys($states));
        $thumbnails->request($profile, $missing, $rows, $mounts, $principal);

        return $this->json(['items' => $this->cards($rows, $mounts, $this->canDownload($request), $profile, $keys, $states)]);
    }

    public function content(Request $request, string $source, string $entry, string $variant, ApplicationCatalog $catalog, MediaResponse $response): Response
    {
        return $this->stream($request, $entry, $variant, $catalog, $response, $source);
    }

    public function catalogContent(Request $request, string $entry, string $variant, ApplicationCatalog $catalog, MediaResponse $response): Response
    {
        return $this->stream($request, $entry, $variant, $catalog, $response);
    }

    private function stream(Request $request, string $entry, string $variant, ApplicationCatalog $catalog, MediaResponse $response, ?string $filter = null): Response
    {
        $mounts = $this->mounts($request, $filter);
        $row = $this->query($mounts)->where('public_id', $entry)->first();
        abort_if($row === null, 404);
        if ($request->boolean('download')) {
            app(StudioPermissions::class)->authorize($request->user(), 'application-files.download');
        }

        $source = $mounts[$row->source_key];
        $principal = Principal::fromUser($request->user());
        $profile = $variant === 'generated-thumbnail' ? ApplicationThumbnailProfile::configured() : null;
        abort_if($variant === 'generated-thumbnail' && ($profile === null || $request->boolean('download')), 404);
        $record = $profile === null
            ? $catalog->content($source, $principal, $row, $variant)
            : app(ApplicationThumbnails::class)->content($profile, $source, $principal, $row);

        return $response->stream($request, $record);
    }

    /** @return array<string, CatalogSource> */
    private function mounts(Request $request, ?string $filter = null): array
    {
        abort_unless(config('media-studio.application_files.enabled') === true && Schema::hasTable('fms_application_entries'), 404);
        $principal = Principal::fromUser($request->user());
        $tenant = app(WorkspaceAccess::class)->tenant($principal);
        $sources = (array) config('media-studio.application_files.sources', []);
        abort_if(count($sources) > 100, 500, 'Configure no more than 100 application sources.');
        $mounts = [];
        foreach ($sources as $key => $config) {
            if (! is_array($config) || ! CatalogSource::enabled($config) || ($filter !== null && $key !== $filter)) {
                continue;
            }
            $source = CatalogSource::find((string) $key, $tenant);
            if ($source->allows($principal, $request->user())) {
                $mounts[$source->key] = $source;
            }
        }
        abort_if($filter !== null && ! isset($mounts[$filter]), 404);

        return $mounts;
    }

    private function query(array $mounts): Builder
    {
        $query = DB::table('fms_application_entries')->useWritePdo();
        if ($mounts === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tenant_scope', reset($mounts)->tenantScope())->where(function (Builder $allowed) use ($mounts): void {
            foreach ($mounts as $source) {
                $allowed->orWhere(fn (Builder $scope) => $scope->where('source_key', $source->key)->where('source_revision', $source->revision()));
            }
        });
    }

    private function cursor(Request $request, array $mounts, array $view): ScopedCursor
    {
        $identity = array_map(fn (CatalogSource $source) => [$source->tenantScope(), $source->revision()], $mounts);

        return new ScopedCursor(hash('sha256', json_encode([Principal::fromUser($request->user())->key(), $identity, $view], JSON_THROW_ON_ERROR)));
    }

    private function visible(ApplicationCatalog $catalog, array $mounts, Principal $principal, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->source_key][] = $row;
        }
        $visible = [];
        foreach ($groups as $key => $group) {
            foreach ($catalog->visible($mounts[$key], $principal, $group) as $row) {
                $visible[$row->id] = true;
            }
        }

        return array_values(array_filter($rows, fn (object $row) => isset($visible[$row->id])));
    }

    private function search(Builder $query, string $search): void
    {
        if ($search !== '') {
            $query->whereRaw("sort_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%']);
        }
    }

    private function canDownload(Request $request): bool
    {
        return app(StudioPermissions::class)->allows($request->user(), 'application-files.download');
    }

    private function cards(array $rows, array $mounts, bool $download, ?ApplicationThumbnailProfile $profile = null, ?array $keys = null, ?array $states = null): array
    {
        $profile ??= $rows === [] ? null : ApplicationThumbnailProfile::configured();
        if ($profile !== null && $keys === null) {
            $thumbnails = app(ApplicationThumbnails::class);
            $keys = $thumbnails->keys($profile, $rows, $mounts);
            $states = $thumbnails->states($keys);
        }
        $worker = $profile?->usesWorker() ?? false;

        return array_map(function (object $row) use ($mounts, $download, $keys, $states, $worker): array {
            $key = $keys[$row->id] ?? null;
            $state = $key === null ? null : ($states[$key] ?? ($worker ? 'queued' : null));

            return $this->card($row, $mounts[$row->source_key], $download, $state);
        }, $rows);
    }

    private function card(object $row, CatalogSource $source, bool $download, ?string $generated): array
    {
        $data = $row->descriptor === null ? [] : json_decode($row->descriptor, true, 16, JSON_THROW_ON_ERROR);
        $base = 'application-files/catalog/entries/'.$row->public_id.'/';
        $mime = $data['mime'] ?? null;
        $type = $mime === null ? null : match (true) {
            str_starts_with($mime, 'image/') => 'image', str_starts_with($mime, 'video/') => 'video', str_starts_with($mime, 'audio/') => 'audio', $mime === 'application/pdf' => 'pdf', default => 'document',
        };

        return ['id' => $row->public_id, 'name' => $row->name, 'kind' => $row->kind, 'readOnly' => true,
            'sourceKey' => $source->key, 'sourceLabel' => (string) ($source->config['label'] ?? $source->key),
            'mediaType' => $type, 'mimeType' => $mime, 'size' => $data['size'] ?? null, 'bytes' => $data['size'] ?? null,
            'versionId' => $row->kind === 'file' ? hash('sha256', $row->descriptor) : null, 'revision' => 1,
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at, 'trashedAt' => null, 'parentId' => null, 'metadata' => (object) [],
            'thumbnailState' => isset($data['thumbnail']) ? 'ready' : ($generated ?? 'none'), 'previewState' => 'ready',
            'thumbnailUrl' => isset($data['thumbnail']) ? ApiUrl::to($base.'thumbnail') : ($generated === 'ready' ? ApiUrl::to($base.'generated-thumbnail') : null),
            'previewUrl' => isset($data['preview']) ? ApiUrl::to($base.'preview') : ($type === 'image' ? ApiUrl::to($base.'original') : null),
            'downloadUrl' => $row->kind === 'file' && $download ? ApiUrl::to($base.'original?download=1') : null];
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
