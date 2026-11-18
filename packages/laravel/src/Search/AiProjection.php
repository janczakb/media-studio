<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\DB;

/** Bounded current-version AI projection, separate from user and Spatie tags. */
final class AiProjection
{
    public function forEntries(iterable $entries): array
    {
        if (config('media-studio.intelligence.enabled') !== true) {
            return [];
        }
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->id;
        }
        if (count($ids) > 120) {
            throw new \InvalidArgumentException('AI projection batches cannot exceed 120 entries.');
        }
        $analyses = DB::table('fms_entries as e')->whereIn('e.id', $ids)->whereNull('e.trashed_at')
            ->select('e.id')->selectSub(DB::table('fms_image_analyses as a')->select('a.id')->whereColumn('a.entry_id', 'e.id')->whereColumn('a.version_id', 'e.current_version_id')->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(1), 'analysis_id')->get();
        $records = DB::table('fms_image_analyses')->whereIn('id', $analyses->pluck('analysis_id')->filter())->get()->keyBy('id');
        $labels = DB::table('fms_ai_labels')->whereIn('analysis_id', $records->keys())->where('review_state', '!=', 'rejected')->orderBy('id')->get(['analysis_id', 'name'])->groupBy('analysis_id');
        $result = [];
        foreach ($analyses as $row) {
            $analysis = $records->get($row->analysis_id);
            if ($analysis !== null) {
                $text = trim($analysis->description.' '.implode(' ', $labels->get($analysis->id, collect())->pluck('name')->all()));
                $result[$row->id] = ['text' => $text, 'hash' => hash('sha256', $analysis->id.':'.$text)];
            }
        }

        return $result;
    }
}
