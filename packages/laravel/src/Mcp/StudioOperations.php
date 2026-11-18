<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Comments\FileComments;
use UseFlex\MediaStudio\Http\FileContent;
use UseFlex\MediaStudio\Library\BulkEntries;
use UseFlex\MediaStudio\Library\CreateFolder;
use UseFlex\MediaStudio\Library\EntryOperations;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Library\PersonalLibrary;
use UseFlex\MediaStudio\Library\ResolveFolders;
use UseFlex\MediaStudio\Trash\TrashDirectory;
use UseFlex\MediaStudio\Uploads\UploadSessions;
use UseFlex\MediaStudio\Workspaces\CreateWorkspace;

final class StudioOperations
{
    public function __construct(
        private McpAccess $access,
        private WorkspaceAccess $workspaces,
        private UploadSessions $uploads,
        private EntryOperations $entries,
        private FileComments $comments,
    ) {}

    public function execute(McpConnection $client, string $tool, array $data): array
    {
        $request = request();
        $actor = $request->user();
        $operation = OperationCatalog::TOOLS[$tool]['operation'];
        if ($tool === 'studio_create_workspace') {
            $workspace = app(CreateWorkspace::class)->execute($actor, $data['name'], hash('sha256', $client->scope().':'.$data['key']));

            return ['id' => $workspace->public_id, 'name' => $workspace->name];
        }

        $workspace = $data['workspace'];
        $this->access->workspace($workspace, $tool);
        $prior = $request->attributes->get('fms.required-operation');
        $request->attributes->set('fms.required-operation', ['workspace' => $workspace, 'operation' => $operation]);
        try {
            $principal = $client->principal;
            $scope = $client->scope();
            $entry = $data['entry'] ?? null;
            $session = $data['session'] ?? null;

            return match ($tool) {
                'studio_create_folder' => self::entry(app(CreateFolder::class)->execute($principal, $workspace, $data['name'], $data['parent'] ?? null)),
                'studio_resolve_import_folder' => app(ResolveFolders::class)->execute($principal, $workspace, $data['parent'] ?? null, [$data['path']])[0],
                'studio_rename_entry', 'studio_move_entry' => self::entry($this->entries->execute($principal, $workspace, $entry, 'update', $data)),
                'studio_trash_entry' => self::entry($this->entries->execute($principal, $workspace, $entry, 'trash', $data)),
                'studio_restore_entry' => self::entry($this->entries->execute($principal, $workspace, $entry, 'restore', $data)),
                'studio_list_trash' => $this->trashPage($client, $workspace, $data),
                'studio_bulk_move', 'studio_bulk_trash', 'studio_bulk_restore' => $this->bulk($client, $workspace, $tool, $data),
                'studio_set_favorite' => app(PersonalLibrary::class)->setStar($principal, $workspace, $entry, (bool) $data['starred']),
                'studio_replace_tags' => app(EntryTags::class)->replace($principal, $workspace, $entry, $data['revision'], array_map(static fn (string $tag) => ['name' => $tag], $data['tags'])),
                'studio_list_comments' => $this->commentPage($this->comments->index($actor, $workspace, $entry, $data, $scope)),
                'studio_find_comment_mentions' => ['items' => array_map(static fn (array $member) => ['memberId' => $member['memberId'], 'name' => $member['name']], $this->comments->mentions($actor, $workspace, $entry, $data)['items'])],
                'studio_create_comment' => self::comment($this->comments->store($actor, $workspace, $entry, [...$data, 'key' => hash('sha256', $scope.':'.$data['key'])])),
                'studio_edit_comment', 'studio_resolve_comment', 'studio_delete_comment' => self::comment($this->comments->update($actor, $workspace, $entry, $data['comment'], [...$data, 'delete' => $tool === 'studio_delete_comment'])),
                'studio_prepare_file_download' => $this->prepareDownload($client, $workspace, $entry, $data['version'] ?? null),
                'studio_get_upload_policy' => $this->uploads->policy($this->workspaces->resolve($principal, $workspace, write: true)),
                'studio_prepare_file_upload', 'studio_prepare_image_import' => $this->prepareUpload($client, $workspace, $tool),
                'studio_begin_upload' => $this->uploadCard($workspace, $this->uploads->create($principal, $workspace, $data, $scope)),
                'studio_get_upload' => $this->uploadCard($workspace, $this->uploads->card($this->uploads->resolve($principal, $workspace, $session, clientScope: $scope))),
                'studio_sign_upload_part' => $this->uploads->signPart($principal, $workspace, $session, (int) $data['part'], $data['sha256'], $scope),
                'studio_record_upload_part' => $this->recordPart($client, $workspace, $session, (int) $data['part'], $data['etag']),
                'studio_complete_upload' => self::entry($this->uploads->complete($principal, $workspace, $session, $scope,
                    fn () => $this->requireTransfer($workspace, 'studio_complete_upload'))),
                'studio_cancel_upload' => $this->cancel($client, $workspace, $session),
            };
        } finally {
            if ($prior === null) {
                $request->attributes->remove('fms.required-operation');
            } else {
                $request->attributes->set('fms.required-operation', $prior);
            }
        }
    }

    public function requireTransfer(string $workspace, string $tool): McpConnection
    {
        $request = request();
        $client = McpConnection::fromRequest($request);
        abort_unless($client !== null && $this->access->allows($request->user(), $tool), 403);
        abort_unless($this->workspaces->tenant($client->principal)?->key() === $client->tenantKey, 403);
        $this->access->workspace($workspace, $tool);

        return $client;
    }

    public static function entry(object $entry): array
    {
        return ['id' => $entry->public_id, 'name' => $entry->name, 'kind' => $entry->kind,
            'revision' => (int) $entry->revision, 'trashedAt' => $entry->trashed_at, 'versionId' => $entry->current_version_id];
    }

    private static function comment(array $comment): array
    {
        $result = array_intersect_key($comment, array_flip(['id', 'body', 'revision', 'createdAt', 'updatedAt', 'resolved', 'deleted']));
        if (isset($comment['author']['name'])) {
            $result['authorName'] = $comment['author']['name'];
        }

        return $result;
    }

    private function commentPage(array $page): array
    {
        return ['items' => array_map(self::comment(...), $page['items']), 'nextCursor' => $page['nextCursor'], 'hasMore' => $page['hasMore']];
    }

    private function trashPage(McpConnection $client, string $workspace, array $data): array
    {
        $page = app(TrashDirectory::class)->page($client->principal, $workspace, $data, $client->scope());
        $fields = array_flip(['id', 'name', 'kind', 'revision', 'trashedAt', 'purging']);
        $page['items'] = array_map(static fn (array $item) => array_intersect_key($item, $fields), $page['items']);

        return array_intersect_key($page, array_flip(['items', 'nextCursor', 'hasMore']));
    }

    private function bulk(McpConnection $client, string $workspace, string $tool, array $data): array
    {
        $items = array_map(static fn (array $item): array => ['id' => $item['id'], 'revision' => (int) $item['revision']], $data['items']);
        $rows = app(BulkEntries::class)->execute($client->principal, $workspace, substr($tool, strlen('studio_bulk_')), $items, $data['parent'] ?? null);

        return ['items' => array_map(self::entry(...), $rows)];
    }

    private function prepareDownload(McpConnection $client, string $workspace, string $entry, ?string $version): array
    {
        $record = app(FileContent::class)->record($client->principal, $workspace, $entry, $version);

        return ['downloadPath' => route('media-studio.mcp.download', ['workspace' => $workspace, 'entry' => $entry,
            'version' => $record->current_version_id], false)];
    }

    private function prepareUpload(McpConnection $client, string $workspace, string $tool): array
    {
        $this->workspaces->resolve($client->principal, $workspace, write: true);

        return ['uploadPath' => route($tool === 'studio_prepare_image_import' ? 'media-studio.mcp.import' : 'media-studio.mcp.upload', ['workspace' => $workspace], false)];
    }

    private function uploadCard(string $workspace, array $card): array
    {
        $card['entry'] = $card['entry'] === null ? null : array_intersect_key($card['entry'], array_flip(['id', 'name', 'revision', 'versionId']));
        if ($card['transport'] === 'local') {
            $card['partPathPrefix'] = substr(route('media-studio.mcp.upload.part', ['workspace' => $workspace, 'session' => $card['id'], 'part' => 0], false), 0, -1);
        }

        return $card;
    }

    private function recordPart(McpConnection $client, string $workspace, string $session, int $part, string $etag): array
    {
        $this->uploads->recordPart($client->principal, $workspace, $session, $part, $etag, $client->scope());

        return ['accepted' => true];
    }

    private function cancel(McpConnection $client, string $workspace, string $session): array
    {
        $this->uploads->cancel($client->principal, $workspace, $session, $client->scope());

        return ['cancelled' => true];
    }
}
