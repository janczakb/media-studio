<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

/** Optional host adapter for Reverb/WebSockets. Deliveries may repeat after a crash. */
interface NavigationPublisher
{
    /** Signal only the workspace/revision; clients fetch authorized deltas separately. */
    public function publish(string $workspace, int $revision): void;
}
