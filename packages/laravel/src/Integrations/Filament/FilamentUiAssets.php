<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class FilamentUiAssets
{
    private const URI = '/assets/flex-media-studio/filament.css';

    public static function boot(Application $app): void
    {
        if (! class_exists(FilamentAsset::class)) {
            return;
        }
        if (! $app->routesAreCached()) {
            Route::get(self::URI, [self::class, 'stylesheet'])->name('media-studio.filament.styles');
        }

        $app->booted(static function (): void {
            FilamentAsset::register([
                Css::make('studio-media', url(self::URI))->html(static fn (): string => self::URI.'?v='.(string) filemtime(self::path())),
            ], package: 'useflex/media-studio');
        });
    }

    public function stylesheet(): BinaryFileResponse
    {
        return response()->file(self::path(), ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/css/filament-media.css';
    }
}
