<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use UseFlex\MediaStudio\Identity\Principal;

interface ExternalVariantProvider
{
    /**
     * Return ready objects belonging to the current authorized source version.
     * Recheck source ownership on each call. Do not copy bytes or create conversions.
     *
     * @return iterable<ExternalVariant>
     */
    public function variants(object $workspace, object $entry, object $version, Principal $principal): iterable;
}
