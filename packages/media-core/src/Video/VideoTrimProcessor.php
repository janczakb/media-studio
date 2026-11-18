<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Video;

/** Implement this contract to use a remote processor without local FFmpeg. */
interface VideoTrimProcessor
{
    public function available(): bool;

    /** @return array{duration:float,width:int,height:int} */
    public function inspect(string $localFile): array;

    /** Write a complete MP4 to a local destination. Never modify the source. */
    public function trim(string $source, string $destination, float $start, float $end): void;
}
