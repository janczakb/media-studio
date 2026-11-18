<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

interface RegistersApplicationMedia
{
    /**
     * Describe committed media from loaded model attributes. No storage or network I/O.
     *
     * @return iterable<ApplicationMedia>
     */
    public function studioMedia(): iterable;
}
