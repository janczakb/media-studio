<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Support\Facades\DB;

/** Read-only, current-version results for an already authorized image preview. */
final class ImageInsights
{
    public function forEntry(object $entry): array
    {
        if ($entry->media_type !== 'image' || $entry->source_kind !== 'managed'
            || $entry->current_version_id === null || $entry->trashed_at !== null) {
            return [];
        }

        $result = [];
        if (config('media-studio.intelligence.enabled') === true && config('media-studio.intelligence.images') === true) {
            $analysis = DB::table('fms_image_analyses')
                ->where('entry_id', $entry->id)->where('version_id', $entry->current_version_id)
                ->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'description', 'provider']);
            if ($analysis !== null) {
                $labels = DB::table('fms_ai_labels')->where('analysis_id', $analysis->id)
                    ->whereIn('review_state', ['accepted', 'pending'])->orderBy('id')->limit(64)
                    ->get(['name'])->pluck('name')->all();
                $result['analysis'] = [
                    'description' => $analysis->description,
                    'tags' => $labels,
                    'sample' => $analysis->provider === 'studio_demo',
                ];
            }
        }

        if (config('media-studio.search.content.enabled') === true
            && config('media-studio.search.content.ocr.enabled') === true
            && config('media-studio.search.content.ocr.images') === true) {
            $ocr = DB::table('fms_search_content')
                ->where('entry_id', $entry->id)->where('version_id', $entry->current_version_id)
                ->whereIn('state', ['ready', 'partial', 'no_text'])->first(['state', 'content']);
            if ($ocr !== null) {
                $limit = 4000;
                $result['ocr'] = [
                    'text' => mb_substr($ocr->content, 0, $limit),
                    'partial' => $ocr->state === 'partial' || mb_strlen($ocr->content) > $limit,
                ];
            }
        }

        return $result;
    }
}
