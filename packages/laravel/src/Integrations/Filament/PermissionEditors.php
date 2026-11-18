<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use UseFlex\MediaStudio\Access\PermissionDefinitions;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilePermissions;

/** Optional role-editor configuration, without depending on Filament or writing DB rows. */
final class PermissionEditors
{
    public function register(): void
    {
        foreach (['guardian' => 'filament-guardian', 'shield' => 'filament-shield'] as $editor => $config) {
            if (config('media-studio.permissions.editors.'.$editor, true) !== true || ! is_array(config($config))) {
                continue;
            }
            $definitions = app(PermissionDefinitions::class)->all();
            if ($editor === 'shield' && config('media-studio.application_files.shield') !== true) {
                foreach (array_keys(ApplicationFilePermissions::definitions()) as $ability) {
                    unset($definitions[$ability]);
                }
            }
            $existing = (array) config($config.'.custom_permissions', []);
            config()->set($config.'.custom_permissions', $existing + $definitions);
            if ($editor === 'guardian') {
                $this->registerGuardianTranslations($existing);
            }
        }
    }

    /** @param array<string, string> $existing Host-owned labels take precedence. */
    private function registerGuardianTranslations(array $existing): void
    {
        $translator = app('translator');
        foreach (array_unique(['en', 'pl', $translator->getLocale()]) as $locale) {
            foreach (app(PermissionDefinitions::class)->all($locale) as $ability => $label) {
                $key = 'filament-guardian::filament-guardian.custom.'.$ability;
                if (! array_key_exists($ability, $existing) && ! $translator->hasForLocale($key, $locale)) {
                    $translator->addLines(['filament-guardian.custom.'.$ability => $label], $locale, 'filament-guardian');
                }
            }
        }
    }
}
