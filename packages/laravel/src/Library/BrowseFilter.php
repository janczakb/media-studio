<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Database\Query\Builder;
use UseFlex\MediaStudio\Identity\Principal;

interface BrowseFilter
{
    /** Validate and return a WHERE-only predicate scoped by Studio to the active workspace. */
    public function apply(Builder $predicate, string $value, object $workspace, Principal $principal): void;
}
