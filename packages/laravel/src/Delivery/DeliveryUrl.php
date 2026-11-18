<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

use LogicException;

final class DeliveryUrl
{
    public static function base(array $profile): string
    {
        $url = $profile['url'] ?? null;
        if (! is_string($url) || strlen($url) > 2048) {
            throw new LogicException('Configure an HTTPS media delivery URL.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new LogicException('Configure an HTTPS media delivery URL without credentials, query or fragment.');
        }

        return rtrim($url, '/');
    }

    public static function object(DeliveryResource $resource, array $profile): string
    {
        return self::base($profile).'/'.implode('/', array_map('rawurlencode', explode('/', $resource->key)));
    }

    public static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function secret(array $profile, string $key): string
    {
        $value = $profile[$key] ?? null;
        if (! is_string($value) || strlen($value) < 16 || strlen($value) > 65536) {
            throw new LogicException('Configure the media delivery signing credential.');
        }

        return $value;
    }
}
