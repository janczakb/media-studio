<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Renditions\Renditions;

/** Project an optional published image preset in one bounded metadata query. */
final class ThumbnailProjection
{
    public static function validatePreset(?string $preset): void
    {
        if ($preset !== null && ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $preset)) {
            throw new InvalidArgumentException('Invalid thumbnail preset.');
        }
    }

    public function apply(string $workspace, array $cards, ?string $preset): array
    {
        self::validatePreset($preset);
        if ($preset === null || $cards === []) {
            return $cards;
        }
        abort_if(count($cards) > 120, 422, 'Project at most 120 thumbnails at once.');
        $ids = array_values(array_filter(array_column($cards, 'id')));
        $versions = array_values(array_filter(array_column($cards, 'versionId')));
        $ready = [];
        foreach (Renditions::published()->where('preset', $preset)->whereIn('version_id', $versions)
            ->whereIn('mime_type', Renditions::IMAGE_MIMES)
            ->whereIn('entry_id', DB::table('fms_entries as e')->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')
                ->where('w.public_id', $workspace)->whereIn('e.public_id', $ids)->whereNull('e.trashed_at')
                ->whereNull('e.purging_at')->whereColumn('e.current_version_id', 'fms_renditions.version_id')->select('e.id'))
            ->get(['version_id']) as $row) {
            $ready[$row->version_id] = true;
        }

        return array_map(static function (array $card) use ($workspace, $preset, $ready): array {
            $version = $card['versionId'] ?? null;
            $card['thumbnailUrl'] = $version !== null && isset($ready[$version])
                ? ApiUrl::to('workspaces/'.$workspace.'/entries/'.$card['id'].'/versions/'.$version.'/renditions/'.$preset)
                : null;
            $card['thumbnailState'] = $card['thumbnailUrl'] === null ? 'none' : 'ready';

            return $card;
        }, $cards);
    }
}
