<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

use RuntimeException;

/** A bounded text-only thumbnail. HTML is parsed as text, never executed. */
final class TextThumbnailGenerator implements ThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        $font = config('media-studio.processing.document_thumbnails.text_font');
        if (! extension_loaded('gd') || ! function_exists('imagettftext') || ! is_string($font) || ! is_file($font)) {
            throw new RuntimeException('document_font_unavailable');
        }
        $text = file_get_contents($sourcePath, false, null, 0, 65536);
        if ($text === false) {
            throw new RuntimeException('source_unavailable');
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Remove active/hidden HTML content without executing scripts or fetching resources.
        $text = preg_replace('~<(script|style|iframe|object|svg)\b[^>]*>.*?</\1\s*>~is', '', $text);
        $text = preg_replace('~</(?:p|div|h[1-6]|li|tr)>|<br\s*/?>~i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $edge = min(1200, $profile['width']);
        $image = imagecreatetruecolor($edge, $edge);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 40, 40, 45);
        imagefill($image, 0, 0, $white);
        $fontSize = max(8, (int) round($edge / 38));
        $margin = max(12, (int) round($edge / 20));
        $y = $margin + $fontSize;
        $maxWidth = $edge - 2 * $margin;
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            // Bound both processing and drawing, including huge unbroken words.
            foreach (mb_str_split(mb_substr($paragraph, 0, 4096)) as $char) {
                $candidate = $line.$char;
                $box = imagettfbbox($fontSize, 0, $font, $candidate);
                if ($line !== '' && $maxWidth < $box[2] - $box[0]) {
                    imagettftext($image, $fontSize, 0, $margin, $y, $ink, $font, $line);
                    $y += (int) ceil($fontSize * 1.65);
                    $line = '';
                    if ($y > $edge - $margin) {
                        break 2;
                    }
                }
                $line .= $char;
            }
            if ($line !== '') {
                imagettftext($image, $fontSize, 0, $margin, $y, $ink, $font, $line);
            }
            $y += (int) ceil($fontSize * 1.65);
            if ($y > $edge - $margin) {
                break;
            }
        }
        $output = tmpfile();
        if (! is_resource($output)) {
            throw new RuntimeException('document_temp_failed');
        }
        if (! imagepng($image, $output)) {
            fclose($output);
            throw new RuntimeException('document_render_failed');
        }
        rewind($output);

        return $output;
    }
}
