<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use UseFlex\MediaStudio\Access\StudioPermissions;

final class ApplicationFilePermissions
{
    public static function sourceAbility(string $key, array $source): ?string
    {
        $ability = array_key_exists('permission', $source) ? $source['permission'] : 'studio.application-files.source.'.$key;

        return is_string($ability) && trim($ability) !== '' ? $ability : null;
    }

    /** @return array<string, string> Exact Spatie keys and display labels for Shield or a role editor. */
    public static function definitions(): array
    {
        $permissions = [
            StudioPermissions::ability('application-files.view') => 'Application files · View',
            StudioPermissions::ability('application-files.download') => 'Application files · Download',
            StudioPermissions::ability('application-files.index') => 'Application files · Index',
            StudioPermissions::ability('application-files.spatie.view') => 'Application files · Spatie catalog',
        ];
        foreach ((array) config('media-studio.application_files.sources', []) as $key => $source) {
            if (! preg_match('/\A[a-z0-9_-]{1,64}\z/', (string) $key) || ! is_array($source) || ! CatalogSource::enabled($source)) {
                continue;
            }
            $ability = self::sourceAbility((string) $key, $source);
            if ($ability !== null) {
                $permissions[$ability] ??= 'Application files · '.($source['label'] ?? $key);
            }
        }

        return $permissions;
    }
}
