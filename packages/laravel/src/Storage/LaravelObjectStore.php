<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use UseFlex\MediaCore\Storage\ObjectStore;

final readonly class LaravelObjectStore implements ObjectStore
{
    public function __construct(private Filesystem $disk) {}

    public function writePrivateStream(string $key, mixed $stream): bool
    {
        return $this->disk->writeStream($key, $stream, ['visibility' => 'private']);
    }
}
