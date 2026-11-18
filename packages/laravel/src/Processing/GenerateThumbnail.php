<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class GenerateThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $workspaceId,
        public string $versionId,
        public string $principalType,
        public string $principalId,
        public ?string $tenantType = null,
        public ?string $tenantId = null,
    ) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(ThumbnailProcessor $processor): void
    {
        $tenant = isset($this->tenantType, $this->tenantId) ? new TenantIdentity($this->tenantType, $this->tenantId) : null;
        $processor->execute($this->workspaceId, $this->versionId, new Principal($this->principalType, $this->principalId), $tenant);
    }
}
