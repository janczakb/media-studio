<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use InvalidArgumentException;

final readonly class ApplicationMedia
{
    /** @param array<string|int, ApplicationMediaObject> $variants Ready variants of this exact revision. */
    public function __construct(
        public string $key,
        public string $revision,
        public ApplicationMediaObject $original,
        public array $variants = [],
        public ?string $thumbnail = null,
        public ?string $preview = null,
        public ?string $name = null,
    ) {
        if (! preg_match('/\A[a-zA-Z0-9_.:-]{1,191}\z/', $key) || $revision === '' || strlen($revision) > 191 || ! mb_check_encoding($revision, 'UTF-8')) {
            throw new InvalidArgumentException('Application media require a stable slot key and a source revision.');
        }
        if (count($variants) > 16) {
            throw new InvalidArgumentException('Register at most 16 ready variants per application file.');
        }
        foreach ($variants as $role => $object) {
            if (! preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', (string) $role) || ! $object instanceof ApplicationMediaObject || $object->bytes === 0
                || ! in_array($object->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true)) {
                throw new InvalidArgumentException('Application media variants must be named raster images.');
            }
        }
    }

    public function descriptor(): array
    {
        return [...$this->original->descriptor(), 'slot' => $this->key, 'revision' => $this->revision,
            'thumbnail' => ($this->variants[$this->thumbnail ?? ''] ?? null)?->descriptor(),
            'preview' => ($this->variants[$this->preview ?? ''] ?? null)?->descriptor()];
    }
}
