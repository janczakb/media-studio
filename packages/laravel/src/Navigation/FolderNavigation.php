<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;

final class FolderNavigation
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private AncestorReader $ancestors, private SourcePolicy $sources) {}

    public function context(Principal $principal, string $workspaceId, string $folderId): array
    {
        return $this->read($principal, $workspaceId, $folderId, null, 4, true);
    }

    public function ancestors(Principal $principal, string $workspaceId, string $folderId, string $cursor, int $limit): array
    {
        return $this->read($principal, $workspaceId, $folderId, $cursor, $limit, false);
    }

    public function changes(Principal $principal, string $workspaceId, int $after): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId);
        $revision = (int) $workspace->navigation_revision;
        if ($after === 0) {
            return ['items' => [], 'revision' => $revision, 'nextRevision' => $revision, 'hasMore' => false, 'reset' => false];
        }
        if ($after > $revision) {
            return ['items' => [], 'revision' => $revision, 'nextRevision' => $revision, 'hasMore' => false, 'reset' => true];
        }
        $scope = DB::table('fms_navigation_changes')->where('workspace_id', $workspace->id)->where('revision', '>', $after)->where('revision', '<=', $revision)->orderBy('revision');
        $length = DB::getDriverName() === 'pgsql' ? 'OCTET_LENGTH(payload::text)' : 'LENGTH(payload)';
        $sizes = (clone $scope)->limit(100)->get(['id', DB::raw($length.' AS payload_bytes')]);
        $ids = [];
        $bytes = 0;
        foreach ($sizes as $row) {
            if ($ids !== [] && $bytes + (int) $row->payload_bytes > 262144) {
                break;
            }
            $ids[] = $row->id;
            $bytes += (int) $row->payload_bytes;
        }
        $rows = $ids === [] ? collect() : (clone $scope)->whereIn('id', $ids)->get(['revision', 'kind', 'payload']);
        $expected = $after + 1;
        $items = [];
        foreach ($rows as $row) {
            if ((int) $row->revision !== $expected) {
                return ['items' => [], 'revision' => $revision, 'nextRevision' => $revision, 'hasMore' => false, 'reset' => true];
            }
            $expected++;
            $items[] = ['revision' => (int) $row->revision, 'kind' => $row->kind, 'payload' => json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR)];
        }
        if ($rows->isEmpty() && $after < $revision) {
            return ['items' => [], 'revision' => $revision, 'nextRevision' => $revision, 'hasMore' => false, 'reset' => true];
        }
        $next = $items === [] ? $after : $items[array_key_last($items)]['revision'];

        return ['items' => $items, 'revision' => $revision, 'nextRevision' => $next, 'hasMore' => $next < $revision, 'reset' => false];
    }

    private function read(Principal $principal, string $workspaceId, string $folderId, ?string $cursor, int $limit, bool $context): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId);
        $revision = (int) $workspace->navigation_revision;
        $folder = $this->entries->resolve($workspace->id, $folderId);
        abort_unless($folder->kind === 'folder' && $folder->trashed_at === null, 404);
        abort_unless($this->sources->visible($workspace, $principal, [$folder]) !== [], 404);
        $scope = hash('sha256', json_encode([$principal->key(), $workspaceId, $folderId], JSON_THROW_ON_ERROR));
        $start = (int) $folder->id;
        if ($cursor !== null) {
            try {
                $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                abort(422, 'Invalid ancestor cursor.');
            }
            abort_unless(is_array($payload) && ($payload['scope'] ?? null) === $scope && ($payload['expires'] ?? 0) > time() && is_int($payload['start'] ?? null), 422, 'Invalid ancestor cursor.');
            abort_unless(($payload['revision'] ?? null) === $revision, 409, 'The folder hierarchy changed. Reload its context.');
            $start = $payload['start'];
        }
        $rows = $this->ancestors->page((int) $workspace->id, $start, $limit + 1);
        abort_if($rows === [], 404);
        $seen = [];
        foreach ($rows as $row) {
            abort_if(isset($seen[$row->id]) || $row->kind !== 'folder' || $row->trashed_at !== null, 409, 'The folder hierarchy is unavailable.');
            $seen[$row->id] = true;
        }
        // Never continue through an unauthorized ancestor or expose its identity.
        $allowed = array_fill_keys(array_column($this->sources->visible($workspace, $principal, $rows), 'id'), true);
        $visible = [];
        foreach ($rows as $row) {
            if (! isset($allowed[$row->id])) {
                break;
            }
            $visible[] = $row;
        }
        $next = count($visible) > $limit ? $visible[$limit] : null;
        $items = array_slice($visible, 0, $limit);
        abort_if($context && $items === [], 404);
        $nextCursor = $next === null ? null : Crypt::encryptString(json_encode([
            'scope' => $scope, 'revision' => $revision, 'start' => (int) $next->id, 'expires' => time() + 900,
        ], JSON_THROW_ON_ERROR));
        // A concurrent committed write must not yield a mixed-revision continuation.
        abort_unless((int) DB::table('fms_workspaces')->where('id', $workspace->id)->value('navigation_revision') === $revision, 409, 'The folder hierarchy changed. Reload its context.');
        $summaries = array_map(static fn (object $row): array => [
            'id' => $row->public_id, 'name' => $row->name, 'readOnly' => $row->source_kind !== 'managed',
        ], $items);
        if (! $context) {
            return ['items' => $summaries, 'nextCursor' => $nextCursor, 'revision' => $revision];
        }

        return [
            'folderId' => $folderId, 'root' => ['id' => null], 'tail' => array_reverse($summaries),
            'hasCollapsedAncestors' => $nextCursor !== null, 'ancestorCursor' => $nextCursor,
            'totalDepth' => null, 'revision' => $revision,
        ];
    }
}
