<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use UseFlex\MediaStudio\Library\MediaReferences;

/** Remove missing pinned versions without discarding temporary uploads or invalid input. */
final class StudioReferenceSelection
{
    public static function available(string $workspace, array $values): array
    {
        abort_if(count($values) > 120, 422, 'Resolve at most 120 media references at once.');
        $references = [];
        foreach ($values as $value) {
            if (is_string($value) && ($reference = StudioReference::parse($value)) !== null) {
                $references[$value] = $reference;
            }
        }
        if ($references === []) {
            return $values;
        }

        // Workspace access failures and backend failures must propagate, never
        // masquerade as an empty library and erase a valid stored selection.
        $available = [];
        foreach (app(MediaReferences::class)->resolve(StudioPicker::principal(), $workspace,
            array_values($references), availableOnly: true) as $file) {
            $available[StudioReference::key($file)] = true;
        }

        return array_filter($values, static fn (mixed $value): bool => ! is_string($value)
            || ! isset($references[$value]) || isset($available[$value]));
    }
}
