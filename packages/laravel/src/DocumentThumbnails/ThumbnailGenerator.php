<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

interface ThumbnailGenerator
{
    /** Return a rewindable raster-image stream owned and closed by the caller. No original mutation. */
    public function rasterize(string $sourcePath, array $profile): mixed;
}
