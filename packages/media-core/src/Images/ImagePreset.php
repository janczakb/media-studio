<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Images;

use InvalidArgumentException;

final readonly class ImagePreset
{
    public function __construct(public string $name, public array $recipe)
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $name)) {
            throw new InvalidArgumentException('Use a lowercase rendition name of at most 64 characters.');
        }
        $allowed = ['width', 'height', 'fit', 'format', 'quality', 'upscale', 'background', 'position', 'disk', 'strip_metadata', 'avif_effort', 'deduplicate'];
        if (array_diff(array_keys($recipe), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported rendition setting.');
        }
        if (isset($recipe['avif_effort']) && (! is_int($recipe['avif_effort']) || $recipe['avif_effort'] < 0 || $recipe['avif_effort'] > 9)) {
            throw new InvalidArgumentException('AVIF effort must be an integer between 0 and 9.');
        }
        if (isset($recipe['deduplicate']) && ! is_bool($recipe['deduplicate'])) {
            throw new InvalidArgumentException('deduplicate must be boolean.');
        }
        foreach (['width', 'height'] as $dimension) {
            if (! isset($recipe[$dimension]) || ! is_int($recipe[$dimension]) || $recipe[$dimension] < 1 || $recipe[$dimension] > 4096) {
                throw new InvalidArgumentException('Rendition dimensions must be between 1 and 4096 pixels.');
            }
        }
        if (isset($recipe['disk']) && (! is_string($recipe['disk']) || $recipe['disk'] === '' || strlen($recipe['disk']) > 255)) {
            throw new InvalidArgumentException('A conversion disk must be a configured disk name.');
        }
        if (! in_array($recipe['fit'] ?? null, ['contain', 'cover', 'stretch', 'pad'], true)
            || ! in_array($recipe['format'] ?? null, ['webp', 'avif'], true)
            || ! is_int($recipe['quality'] ?? null) || $recipe['quality'] < 1 || $recipe['quality'] > 100
            || ! is_bool($recipe['upscale'] ?? null)
            || ! is_bool($recipe['strip_metadata'] ?? true)
            || ! preg_match('/\A#[0-9a-fA-F]{6}\z/', $recipe['background'] ?? '')
            || ! in_array($recipe['position'] ?? null, ['center', 'top', 'bottom', 'left', 'right'], true)) {
            throw new InvalidArgumentException('Invalid rendition fit, format, quality, upscale, background or position.');
        }
    }

    public function hash(): string
    {
        $recipe = $this->recipe;
        ksort($recipe);

        return hash('sha256', json_encode($recipe, JSON_THROW_ON_ERROR));
    }

    public function mime(): string
    {
        return 'image/'.$this->recipe['format'];
    }
}
