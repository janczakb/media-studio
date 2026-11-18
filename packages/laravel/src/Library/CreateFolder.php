<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

final class CreateFolder
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private UploadAsset $uploads) {}

    public function execute(Principal $principal, string $workspaceId, string $name, ?string $parent = null): object
    {
        $name = trim($name);
        $this->uploads->validateName($name);

        try {
            return DB::transaction(function () use ($principal, $workspaceId, $parent, $name): object {
                $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
                $parentId = $this->entries->parentId($workspace->id, $parent, write: true);
                $publicId = (string) Str::ulid();
                DB::table('fms_entries')->insert([
                    'public_id' => $publicId, 'workspace_id' => $workspace->id,
                    'parent_id' => $parentId, 'parent_scope' => $parentId ?? 0, 'kind' => 'folder', 'sort_group' => 0,
                    'name' => $name, 'normalized_name' => $this->entries->normalizeName($name), 'created_at' => now(), 'updated_at' => now(),
                ]);

                $folder = $this->entries->resolve($workspace->id, $publicId);
                app(NavigationChanges::class)->entries((int) $workspace->id, 'created', [$folder]);

                return $folder;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'A folder with this name already exists.');
        }
    }
}
