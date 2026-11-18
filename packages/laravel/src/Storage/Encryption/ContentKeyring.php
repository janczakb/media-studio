<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use SensitiveParameter;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Separate content keys; never silently reuse Laravel's session/cookie APP_KEY. */
final class ContentKeyring
{
    private array $keys = [];

    public function __construct(#[SensitiveParameter] array $keys, public readonly string $active)
    {
        if (count($keys) < 1 || count($keys) > 16) {
            throw new EncryptionFailure('Configure between one and sixteen content keys.');
        }
        foreach ($keys as $id => $encoded) {
            if (! is_string($id) || ! preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $id) || ! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
                throw new EncryptionFailure('Invalid content key configuration.');
            }
            $key = base64_decode(substr($encoded, 7), true);
            if ($key === false || strlen($key) !== 32) {
                throw new EncryptionFailure('Content keys must contain 256 random bits.');
            }
            $this->keys[$id] = $key;
        }
        $this->get($active);
    }

    public function ids(): array
    {
        return array_keys($this->keys);
    }

    public function get(string $id): string
    {
        return $this->keys[$id] ?? throw new EncryptionFailure('The required content key is unavailable.');
    }

    public function __debugInfo(): array
    {
        return ['active' => $this->active, 'keyIds' => array_keys($this->keys)];
    }

    public function __serialize(): array
    {
        throw new EncryptionFailure('Content keys cannot be serialized.');
    }
}
