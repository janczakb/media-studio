<?php

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RetireWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $operation) {}

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(WorkspaceRetirement $service): void
    {
        $service->step($this->operation);
    }
}
