<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery\Adapters;

use UseFlex\MediaStudio\Delivery\DeliveryAdapter;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Delivery\DeliveryUrl;

/** Exact-file HMAC-SHA256 token; no wildcard directory delegation. */
final class BunnyUrl implements DeliveryAdapter
{
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string
    {
        $url = DeliveryUrl::object($resource, $profile);
        $path = parse_url($url, PHP_URL_PATH);
        $data = 'fmsv='.$resource->cacheKey();
        $token = 'HS256-'.DeliveryUrl::base64(hash_hmac('sha256', $path.$expiresAt.$data, DeliveryUrl::secret($profile, 'token_key'), true));

        return $url.'?token='.$token.'&expires='.$expiresAt.'&'.$data;
    }
}
