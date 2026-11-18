<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Access;

use InvalidArgumentException;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilePermissions;

/** Canonical, write-free permission catalogue for installers and role editors. */
final class PermissionDefinitions
{
    /** @return array<string, string> Exact Spatie ability => localized label. */
    public function all(?string $locale = null): array
    {
        $labels = (array) trans('media-studio::permissions.operations', [], $locale);
        $overrides = (array) config('media-studio.permissions.labels', []);
        $definitions = [];
        foreach (StudioPermissions::OPERATIONS as $operation) {
            $ability = StudioPermissions::ability($operation);
            $label = $overrides[$ability] ?? $labels[$operation] ?? null;
            if (! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('A display label is required for Studio permission '.$ability.'.');
            }
            $definitions[$ability] ??= $label;
        }
        foreach (ApplicationFilePermissions::definitions() as $ability => $label) {
            if (isset($definitions[$ability])) {
                continue;
            }
            $source = explode(' · ', $label, 2)[1] ?? $label;
            $label = $overrides[$ability] ?? trans('media-studio::permissions.source', ['source' => $source], $locale);
            if (! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('A display label is required for Studio permission '.$ability.'.');
            }
            $definitions[$ability] = $label;
        }

        return $definitions;
    }
}
