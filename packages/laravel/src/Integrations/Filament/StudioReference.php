<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Illuminate\Support\Str;

final class StudioReference
{
    /** @return array{assetId: string, versionId: string}|null */
    public static function parse(string $value): ?array
    {
        $parts = explode(':', $value, 3);
        if (count($parts) !== 2 || ! Str::isUlid($parts[0]) || ! Str::isUlid($parts[1])) {
            return null;
        }

        return ['assetId' => $parts[0], 'versionId' => $parts[1]];
    }

    public static function key(array $reference): string
    {
        return $reference['assetId'].':'.$reference['versionId'];
    }
}
