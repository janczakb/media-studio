<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery\Adapters;

use UseFlex\MediaStudio\Delivery\DeliveryAdapter;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Delivery\DeliveryUrl;

final class WorkerUrl implements DeliveryAdapter
{
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string
    {
        $base = DeliveryUrl::base($profile);
        $ticket = DeliveryUrl::base64(json_encode([
            'v' => 1, 'aud' => $base, 'exp' => $expiresAt,
            'disk' => $resource->disk, 'key' => $resource->key, 'cache' => $resource->cacheKey(),
            'mime' => $resource->mime, 'name' => $resource->name, 'download' => $resource->download,
            'grant' => $profile['grant'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = DeliveryUrl::base64(hash_hmac('sha256', $ticket, DeliveryUrl::secret($profile, 'token_key'), true));

        return $base.'?ticket='.$ticket.'&signature='.$signature;
    }
}
