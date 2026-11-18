<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Navigation\AncestorReader;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Trash\TrashShares;

final class EntryOperations
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private UploadAsset $uploads) {}

    public function details(object $workspace, string $publicId): array
    {
        $row = $this->entries->resolve($workspace->id, $publicId);
        $row->workspace_public_id = $workspace->public_id;
        $parent = $row->parent_id === null ? null : DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $row->parent_id)->first(['public_id', 'name']);

        return [...$this->entries->card($row), 'parentId' => $parent?->public_id, 'parentName' => $parent?->name, 'metadata' => $row->metadata === null ? (object) [] : json_decode($row->metadata, false, 8, JSON_THROW_ON_ERROR), 'createdAt' => $row->created_at];
    }

    /** Mutations serialize structural changes within a workspace, never storage I/O. */
    public function execute(Principal $principal, string $workspaceId, string $entryId, string $operation, array $data, ?\Closure $authorize = null): object
    {
        try {
            return DB::transaction(function () use ($principal, $workspaceId, $entryId, $operation, $data, $authorize): object {
                $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
                $authorize?->__invoke($workspace);
                $row = $this->entries->resolve($workspace->id, $entryId);
                $drawingOnly = $operation === 'update' && array_key_exists('drawing', $data)
                    && array_diff(array_keys($data), ['revision', 'drawing']) === [];
                if ($drawingOnly) {
                    abort_if(($row->purging_at ?? null) !== null, 409, 'This item is being permanently removed.');
                    app(SourcePolicy::class)->readable($workspace, $principal, $row);
                } else {
                    app(SourcePolicy::class)->managed($row);
                }
                abort_unless($row->revision === $data['revision'], 409, 'This item changed in another session. Refresh before saving.');
                $changes = ['revision' => $row->revision + 1, 'updated_at' => now()];

                if ($operation === 'restore') {
                    abort_if($row->trashed_at === null, 409, 'This item is not in the trash.');
                    if ($row->parent_id !== null) {
                        abort_unless(DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $row->parent_id)->whereNull('trashed_at')->exists(), 409, 'The original folder is unavailable.');
                    }
                    $changes['trashed_at'] = null;
                } else {
                    abort_if($row->trashed_at !== null, 409, 'Restore this item before editing it.');
                    if ($operation === 'trash') {
                        abort_if(DB::table('fms_usages')->where('entry_id', $row->id)->exists(), 409, 'This file is still used in the application. Remove its references first.');
                        // Non-empty folder removal belongs to the bounded background operations module.
                        abort_if($row->kind === 'folder' && DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_id', $row->id)->exists(), 409, 'Move the contents before removing this folder.');
                        $changes['trashed_at'] = now();
                        app(TrashShares::class)->remove((int) $workspace->id, DB::table('fms_entries')->where('id', $row->id)->select('id'));
                    } else {
                        if (array_key_exists('name', $data)) {
                            $name = trim($data['name']);
                            $this->uploads->validateName($name);
                            $changes['name'] = $name;
                            $changes['normalized_name'] = $this->entries->normalizeName($name);
                        }
                        if (array_key_exists('metadata', $data) || array_key_exists('drawing', $data)) {
                            $metadata = $row->metadata === null ? [] : json_decode($row->metadata, true, 8, JSON_THROW_ON_ERROR);
                            $metadata = array_replace($metadata, $data['metadata'] ?? []);
                            if (array_key_exists('drawing', $data)) {
                                abort_unless($row->media_type === 'image' && $row->current_version_id === $data['drawing']['versionId'], 409, 'The image version has changed.');
                                $metadata['drawing'] = $data['drawing'];
                            }
                            $changes['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);
                        }
                        if (array_key_exists('parent', $data)) {
                            $parentId = $this->entries->parentId($workspace->id, $data['parent'], write: true);
                            $this->assertNoCycle($workspace->id, $row->id, $parentId);
                            $changes['parent_id'] = $parentId;
                            $changes['parent_scope'] = $parentId ?? 0;
                        }
                    }
                }
                $updated = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $row->id)->where('revision', $data['revision'])->update($changes);
                abort_unless($updated === 1, 409, 'This item changed in another session.');

                $fresh = $this->entries->resolve($workspace->id, $entryId);
                if (array_key_exists('name', $changes) || array_key_exists('parent_id', $changes) || array_key_exists('trashed_at', $changes)) {
                    app(NavigationChanges::class)->entries((int) $workspace->id, $operation, [$fresh], [(int) $row->id => $row->parent_id]);
                }
                if (in_array($operation, ['trash', 'restore'], true)) {
                    app(ActivityLog::class)->record($workspace, $principal, $fresh, $operation === 'trash' ? 'trashed' : 'restored');
                }

                return $fresh;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'An item with this name already exists in the destination folder.');
        }
    }

    /** @param int|list<int> $entryIds */
    public function assertNoCycle(int $workspaceId, int|array $entryIds, ?int $parent): void
    {
        app(AncestorReader::class)->assertDestination($workspaceId, (array) $entryIds, $parent);
    }
}
