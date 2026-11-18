<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Storage;

interface ObjectStore
{
    /** @param resource $stream The caller retains ownership of the stream. */
    public function writePrivateStream(string $key, mixed $stream): bool;
}
