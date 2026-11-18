<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PurgeTrashEntry implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $purgeId) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(TrashRetention $retention): void
    {
        $retention->run($this->purgeId);
    }
}
