<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\DB;

final class ContentProjection
{
    /** Batched optional projection. Disabled mode does not require the optional schema. */
    public function forEntries(iterable $entries, bool $includeText = false): array
    {
        if (config('media-studio.search.content.enabled') !== true) {
            return [];
        }
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->id;
        }
        if (count($ids) > 120) {
            throw new \InvalidArgumentException('Content resolution is limited to120 entries.');
        }

        return DB::table('fms_search_content')->whereIn('entry_id', $ids)->get($includeText ? ['*'] : ['entry_id', 'version_id', 'fingerprint', 'state'])->keyBy('entry_id')->all();
    }

    public static function current(?object $projection, object $entry): bool
    {
        return $projection !== null && $entry->trashed_at === null && $projection->version_id === $entry->current_version_id;
    }
}
