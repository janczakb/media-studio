<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

interface DeliveryProfileResolver
{
    /** Return a registered profile name; null keeps the configured mapping. */
    public function resolve(DeliveryResource $resource): ?string;
}
