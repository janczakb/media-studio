<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery\Adapters;

use LogicException;
use UseFlex\MediaStudio\Delivery\DeliveryAdapter;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Delivery\DeliveryUrl;

final class PublicUrl implements DeliveryAdapter
{
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string
    {
        if (($profile['public'] ?? false) !== true) {
            throw new LogicException('Public media delivery requires explicit public=true acknowledgement.');
        }

        return DeliveryUrl::object($resource, $profile).'?fmsv='.$resource->cacheKey();
    }
}
