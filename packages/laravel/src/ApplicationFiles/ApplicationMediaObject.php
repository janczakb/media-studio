<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use InvalidArgumentException;

final readonly class ApplicationMediaObject
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public int $bytes,
    ) {
        if ($bytes < 0 || strlen($mime) > 127 || ! preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~D', $mime)) {
            throw new InvalidArgumentException('Declare the actual MIME type and byte length of the stored object.');
        }
    }

    /** @return array{disk: string, path: string, mime: string, size: int} */
    public function descriptor(): array
    {
        return ['disk' => $this->disk, 'path' => $this->path, 'mime' => $this->mime, 'size' => $this->bytes];
    }
}
