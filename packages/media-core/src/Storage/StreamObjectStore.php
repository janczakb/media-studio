<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Storage;

use InvalidArgumentException;
use RuntimeException;

final readonly class StreamObjectStore
{
    public function __construct(private ObjectStore $disk) {}

    /** @param resource $stream */
    public function write(string $key, mixed $stream): void
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A readable stream is required.');
        }

        if (! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $key)) {
            throw new InvalidArgumentException('Invalid managed object key.');
        }

        if (! $this->disk->writePrivateStream($key, $stream)) {
            throw new RuntimeException('The storage provider rejected the write.');
        }
    }
}
