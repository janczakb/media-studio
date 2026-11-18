<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Images;

use RuntimeException;

final class EncodedImageVerifier
{
    /** Validate actual bytes before publication, never trust an extension or encoder return value. */
    public function verify(mixed $stream, string $mime, array $dimensions): void
    {
        $path = stream_get_meta_data($stream)['uri'];
        $info = @getimagesize($path);
        if ($info === false || ($info['mime'] ?? null) !== $mime || $info[0] !== $dimensions['width'] || $info[1] !== $dimensions['height']) {
            throw new RuntimeException('encoded_image_invalid');
        }
        if ($mime === 'image/avif') {
            rewind($stream);
            $header = fread($stream, 4096);
            if (! is_string($header) || strlen($header) < 16 || substr($header, 4, 4) !== 'ftyp') {
                throw new RuntimeException('encoded_image_invalid');
            }
            $length = unpack('N', substr($header, 0, 4))[1];
            if ($length < 16 || $length > strlen($header)) {
                throw new RuntimeException('encoded_image_invalid');
            }
            $brands = [substr($header, 8, 4), ...str_split(substr($header, 16, $length - 16), 4)];
            if (! in_array('avif', $brands, true)) {
                throw new RuntimeException('encoded_image_invalid');
            }
        }
        rewind($stream);
    }
}
