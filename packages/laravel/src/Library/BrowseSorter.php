<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

interface BrowseSorter
{
    /** An indexed, non-null scalar column on fms_entries. */
    public function column(): string;
}
