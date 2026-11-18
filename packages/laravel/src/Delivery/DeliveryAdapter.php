<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

interface DeliveryAdapter
{
    /** Never perform storage/network I/O when signing a URL. */
    public function url(DeliveryResource $resource, array $profile, int $expiresAt): string;
}
