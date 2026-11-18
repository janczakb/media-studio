<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class EntryTags
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries) {}

    /** @param list<array{id?:string,name?:string,locale?:string}> $tags */
    public function replace(Principal $principal, string $workspaceId, string $entryId, int $revision, array $tags): array
    {
        return DB::transaction(function () use ($principal, $workspaceId, $entryId, $revision, $tags): array {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $entry = $this->entries->resolve($workspace->id, $entryId);
            app(SourcePolicy::class)->managed($entry);
            abort_if($entry->trashed_at !== null || $entry->revision !== $revision, 409, 'Refresh this item before editing tags.');
            $this->project($workspace, (int) $entry->id, $tags);
            $updatedAt = now()->toDateTimeString();
            DB::table('fms_entries')->where('id', $entry->id)->update(['revision' => $revision + 1, 'updated_at' => $updatedAt]);

            return ['revision' => $revision + 1, 'updatedAt' => $updatedAt, 'tags' => $this->forEntry((int) $entry->id)];
        }, 3);
    }

    /** Trusted source/import projection. It never edits the original media model. */
    public function project(object $workspace, int $entryId, array $tags): void
    {
        DB::transaction(fn () => $this->writeProjection($workspace, $entryId, $tags), 3);
    }

    private function writeProjection(object $workspace, int $entryId, array $tags): void
    {
        $data = Validator::make(['tags' => $tags], [
            'tags' => ['present', 'array', 'max:32'],
            'tags.*' => ['required', 'array:id,name,locale'],
            'tags.*.id' => ['sometimes', 'required', 'ulid'],
            'tags.*.name' => ['required_without:tags.*.id', 'string', 'max:120'],
            'tags.*.locale' => ['sometimes', 'required', 'string', 'in:'.implode(',', self::locales())],
        ])->validate();
        abort_unless(DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $entryId)->exists(), 404);
        $ids = [];
        foreach ($data['tags'] as $tag) {
            if (isset($tag['id'])) {
                $id = DB::table('fms_tags')->where('workspace_id', $workspace->id)->where('public_id', $tag['id'])->value('id');
                abort_if($id === null, 422, 'A selected tag is unavailable.');
            } else {
                $name = trim($tag['name']);
                abort_if($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name), 422, 'Enter a valid tag name.');
                $locale = $tag['locale'] ?? self::locales()[0];
                $normalized = mb_strtolower($name, 'UTF-8');
                $query = DB::table('fms_tags')->where('workspace_id', $workspace->id)->where('locale', $locale)->where('normalized_name', $normalized);
                $id = (clone $query)->value('id');
                if ($id === null) {
                    DB::table('fms_tags')->insertOrIgnore(['public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'locale' => $locale, 'name' => $name, 'normalized_name' => $normalized]);
                    $id = $query->value('id');
                }
            }
            $ids[(int) $id] = true;
        }
        DB::table('fms_entry_tags')->where('entry_id', $entryId)->delete();
        if ($ids !== []) {
            DB::table('fms_entry_tags')->insert(array_map(static fn (int $id): array => ['entry_id' => $entryId, 'tag_id' => $id], array_keys($ids)));
        }
    }

    public function forEntry(int $entryId): array
    {
        return DB::table('fms_entry_tags as et')->join('fms_tags as t', 't.id', '=', 'et.tag_id')->where('et.entry_id', $entryId)
            ->orderBy('t.normalized_name')->orderBy('t.id')->limit(32)->get(['t.public_id as id', 't.name as label', 't.locale'])->map(fn ($row): array => (array) $row)->all();
    }

    public static function locales(): array
    {
        $locales = array_values(array_unique(array_filter((array) config('media-studio.browse.tag_locales', ['en', 'pl']), static fn ($locale): bool => is_string($locale) && preg_match('/\A[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})?\z/', $locale))));
        if ($locales === [] || count($locales) > 32) {
            throw new \LogicException('Configure between one and 32 tag locales.');
        }

        return $locales;
    }
}
