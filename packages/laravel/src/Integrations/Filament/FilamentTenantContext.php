<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

final class FilamentTenantContext
{
    public const ORIGIN_ATTRIBUTE = 'useflex.media-studio.filament-origin';

    private const VERIFIED_ATTRIBUTE = 'useflex.media-studio.verified-origin';

    public static function register(): void
    {
        if (app()->bound(self::class)) {
            return;
        }
        app()->instance(self::class, new self);
        \Livewire\on('snapshot-verified', static function (array $snapshot): void {
            if (! app(HandleRequests::class)->isLivewireRoute()) {
                return;
            }
            $memo = $snapshot['memo'] ?? [];
            $origin = is_string($memo['path'] ?? null) && is_string($memo['method'] ?? null)
                ? strtoupper($memo['method']).'|'.trim($memo['path'], '/') : null;
            request()->attributes->set(self::VERIFIED_ATTRIBUTE, $origin);
        });
    }

    public static function establish(Request $request): void
    {
        $origin = strtoupper($request->method()).'|'.trim($request->path(), '/');
        $request->attributes->set(self::ORIGIN_ATTRIBUTE, $origin);
        app('request')->attributes->set(self::ORIGIN_ATTRIBUTE, $origin);
    }

    public static function matches(Request $request): bool
    {
        $expected = app(HandleRequests::class)->isLivewireRoute()
            ? $request->attributes->get(self::VERIFIED_ATTRIBUTE)
            : strtoupper($request->method()).'|'.trim($request->path(), '/');

        return is_string($expected) && $request->attributes->get(self::ORIGIN_ATTRIBUTE) === $expected;
    }
}
