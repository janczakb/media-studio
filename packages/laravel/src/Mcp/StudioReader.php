<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Http\StudioController;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Search\SearchController;

final class StudioReader
{
    public function workspaces(Authenticatable $actor, array $arguments): array
    {
        app(StudioPermissions::class)->authorize($actor, 'view');
        $principal = Principal::fromUser($actor);
        $access = app(WorkspaceAccess::class);
        $allowed = app(McpAccess::class)->workspaces();
        $client = McpConnection::fromRequest(request());
        $cursor = new ScopedCursor(hash('sha256', json_encode([$principal->key(), $access->tenant($principal)?->key(), $client?->id, $allowed], JSON_THROW_ON_ERROR)));
        $query = $access->query($principal);
        if (is_array($allowed)) {
            $query->whereIn('w.public_id', $allowed);
        }
        $page = $query->orderBy('w.id')->cursorPaginate(25, ['w.*', 'm.role'], 'cursor', $cursor->decode($arguments['cursor'] ?? null));

        return ['items' => $page->filter(fn (object $row): bool => app(StudioPermissions::class)->allows($actor, 'view', $row))->map(fn (object $row): array => ['id' => $row->public_id, 'name' => $row->name])->values()->all(),
            'nextCursor' => $cursor->encode($page->nextCursor()), 'hasMore' => $page->hasMorePages()];
    }

    public function files(Authenticatable $actor, array $arguments): array
    {
        $this->scope($actor, $arguments['workspace'], 'studio_list_files');
        $result = app(StudioController::class)->browse($this->request($actor, [
            'view' => 'files', 'limit' => 60, ...array_intersect_key($arguments, array_flip(['parent', 'search', 'cursor'])),
        ]), $arguments['workspace'])->getData(true);

        return ['items' => array_map($this->card(...), $result['items']), 'nextCursor' => $result['nextCursor'], 'hasMore' => $result['hasMore']];
    }

    public function file(Authenticatable $actor, array $arguments): array
    {
        $scope = $this->scope($actor, $arguments['workspace'], 'studio_get_file');
        $principal = Principal::fromUser($actor);
        $entry = app(Entries::class)->resolve($scope->id, $arguments['entry']);
        abort_if($entry->trashed_at !== null || (config('media-studio.application_files.enabled') === true && $entry->source_kind !== 'managed'), 404);
        app(SourcePolicy::class)->readable($scope, $principal, $entry);

        return ['file' => ['id' => $entry->public_id, 'name' => $entry->name, 'kind' => $entry->kind,
            'mediaType' => $entry->media_type, 'mimeType' => $entry->mime_type, 'size' => $entry->size_bytes,
            'width' => $entry->width, 'height' => $entry->height, 'revision' => (int) $entry->revision, 'updatedAt' => $entry->updated_at]];
    }

    public function search(Authenticatable $actor, array $arguments): array
    {
        $this->scope($actor, $arguments['workspace'], 'studio_search_files');

        return app(SearchController::class)->index($this->request($actor, array_intersect_key($arguments, array_flip(['q', 'cursor']))), $arguments['workspace'])->getData(true);
    }

    private function scope(Authenticatable $actor, string $workspace, string $tool): object
    {
        app(McpAccess::class)->workspace($workspace, $tool);
        $scope = app(WorkspaceAccess::class)->resolve(Principal::fromUser($actor), $workspace);
        app(StudioPermissions::class)->authorize($actor, 'view', $scope);

        return $scope;
    }

    private function request(Authenticatable $actor, array $arguments): Request
    {
        $request = Request::create('/', 'GET', $arguments);
        $request->setUserResolver(static fn () => $actor);

        return $request;
    }

    private function card(array $entry): array
    {
        return array_intersect_key($entry, array_flip(['id', 'name', 'kind', 'mediaType', 'mimeType', 'size', 'width', 'height', 'revision', 'updatedAt']));
    }
}
