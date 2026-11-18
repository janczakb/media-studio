<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

final class ResolveFolders
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private UploadAsset $uploads) {}

    /** @param list<string> $paths @return list<array{path: string, id: string}> */
    public function execute(Principal $principal, string $workspaceId, ?string $parent, array $paths): array
    {
        abort_if(count($paths) > 100, 422, 'Resolve at most 100 folder paths at once.');
        $segments = [];
        $cost = 0;
        foreach (array_unique($paths) as $path) {
            abort_if(str_contains($path, '\\') || strlen($path) > 4096, 422, 'Invalid folder path.');
            $parts = explode('/', $path);
            foreach ($parts as $part) {
                $this->uploads->validateName($part);
                abort_unless($part === trim($part), 422, 'Folder names cannot start or end with whitespace.');
            }
            $segments[$path] = $parts;
            $cost += count($parts);
        }
        abort_if($cost > 500, 422, 'Resolve at most 500 path segments at once.');

        return DB::transaction(function () use ($principal, $workspaceId, $parent, $segments): array {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $base = $this->entries->parentId($workspace->id, $parent, write: true);
            $resolved = [];
            $results = [];
            $created = false;
            foreach ($segments as $path => $parts) {
                $parentId = $base;
                $prefix = '';
                foreach ($parts as $part) {
                    $prefix = $prefix === '' ? $part : $prefix.'/'.$part;
                    $cacheKey = mb_strtolower($prefix, 'UTF-8');
                    if (! isset($resolved[$cacheKey])) {
                        $name = $this->entries->normalizeName($part);
                        $siblings = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_scope', $parentId ?? 0)
                            ->where('normalized_name', $name)->whereNull('trashed_at');
                        $row = (clone $siblings)->where('kind', 'folder')->first();
                        abort_if($row === null && $siblings->exists(), 409, 'A folder path conflicts with an existing file.');
                        if ($row !== null) {
                            app(SourcePolicy::class)->managed($row);
                        }
                        if ($row === null) {
                            $created = true;
                            $publicId = (string) Str::ulid();
                            $id = DB::table('fms_entries')->insertGetId([
                                'public_id' => $publicId, 'workspace_id' => $workspace->id, 'parent_id' => $parentId,
                                'parent_scope' => $parentId ?? 0, 'kind' => 'folder', 'sort_group' => 0,
                                'name' => $part, 'normalized_name' => $name, 'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $row = (object) ['id' => $id, 'public_id' => $publicId];
                        }
                        $resolved[$cacheKey] = $row;
                    }
                    $parentId = (int) $resolved[$cacheKey]->id;
                }
                $results[] = ['path' => $path, 'id' => $resolved[mb_strtolower($prefix, 'UTF-8')]->public_id];
            }

            if ($created) {
                app(NavigationChanges::class)->record((int) $workspace->id, 'created', []);
            }

            return $results;
        }, 3);
    }
}
