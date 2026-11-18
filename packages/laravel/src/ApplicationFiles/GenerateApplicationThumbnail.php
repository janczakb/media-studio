<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class GenerateApplicationThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly string $key,
        public readonly string $token,
        public readonly int $entry,
        public readonly string $source,
        public readonly Principal $principal,
        public readonly ?TenantIdentity $tenant,
    ) {}

    public function handle(ApplicationThumbnails $thumbnails): void
    {
        $thumbnails->generate($this);
    }
}
