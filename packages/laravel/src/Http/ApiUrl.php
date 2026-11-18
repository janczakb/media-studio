<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use LogicException;

final class ApiUrl
{
    public static function base(): string
    {
        if (is_string($base = request()->attributes->get('fms.api-base'))) {
            return $base;
        }
        $binding = config('media-studio.routes.base_url_resolver');
        if ($binding === null) {
            return '/'.trim((string) config('media-studio.routes.prefix'), '/');
        }

        $resolver = app($binding);
        if (! $resolver instanceof ApiBaseUrlResolver) {
            throw new LogicException('The Studio API URL resolver must implement ApiBaseUrlResolver.');
        }

        return rtrim($resolver->resolve(), '/');
    }

    public static function to(string $path): string
    {
        return url(rtrim(self::base(), '/').'/'.ltrim($path, '/'));
    }
}
