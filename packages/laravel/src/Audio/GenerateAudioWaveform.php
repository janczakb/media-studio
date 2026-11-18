<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Audio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class GenerateAudioWaveform implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 110;

    public int $backoff = 60;

    public function __construct(
        public string $workspaceId,
        public string $versionId,
        public Principal $principal,
        public ?TenantIdentity $tenant,
    ) {}

    public function handle(AudioWaveforms $waveforms): void
    {
        $waveforms->generate($this->workspaceId, $this->versionId, $this->principal, $this->tenant);
    }
}
