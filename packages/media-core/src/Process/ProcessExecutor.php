<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Process;

/** Execute argument arrays directly, with bounded output and guaranteed process teardown. */
interface ProcessExecutor
{
    /** @param list<string> $arguments @param array<string,string> $environment */
    public function capture(array $arguments, int $timeout, int $maxOutput, array $environment = []): string;

    /** @param list<string> $arguments Monitor a caller-owned destination without buffering its contents. */
    public function writeFile(array $arguments, int $timeout, string $destination, int $maximum, string $failure = 'encoding_failed'): void;
}
