<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

final class ImageDrawing implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) !== 2 || ! is_string($value['versionId'] ?? null)
            || ! Str::isUlid($value['versionId']) || ! is_array($value['marks'] ?? null)
            || ! array_is_list($value['marks']) || count($value['marks']) > 64) {
            $fail('The image drawing is invalid.');

            return;
        }

        foreach ($value['marks'] as $mark) {
            if (! $this->validMark($mark)) {
                $fail('The image drawing contains an invalid mark.');

                return;
            }
        }
    }

    private function validMark(mixed $mark): bool
    {
        if (! is_array($mark) || array_diff(array_keys($mark), ['tool', 'color', 'points', 'text', 'strokeWidth', 'opacity', 'fill', 'dash', 'fontSize', 'fontFamily', 'textAlign', 'verticalAlign'])
            || ! in_array($mark['tool'] ?? null, ['pen', 'arrow', 'line', 'rectangle', 'ellipse', 'text'], true)
            || ! is_string($mark['color'] ?? null) || ! preg_match('/\A#[a-fA-F0-9]{6}\z/', $mark['color'])
            || ! is_array($mark['points'] ?? null) || ! array_is_list($mark['points'])) {
            return false;
        }

        $count = count($mark['points']);
        if ($count < 1 || $count > ($mark['tool'] === 'pen' ? 128 : 2)) {
            return false;
        }

        foreach (['strokeWidth' => [1, 12], 'opacity' => [0, 1], 'fontSize' => [12, 96]] as $key => [$min, $max]) {
            if (array_key_exists($key, $mark) && (! is_int($mark[$key]) && ! is_float($mark[$key]) || ! is_finite((float) $mark[$key]) || $mark[$key] < $min || $mark[$key] > $max)) {
                return false;
            }
        }
        foreach (['fill' => ['none', 'semi', 'solid', 'pattern'], 'dash' => ['solid', 'dashed', 'dotted'], 'fontFamily' => ['sans-serif', 'serif', 'monospace', 'cursive'], 'textAlign' => ['start', 'middle', 'end'], 'verticalAlign' => ['start', 'middle', 'end']] as $key => $allowed) {
            if (array_key_exists($key, $mark) && ! in_array($mark[$key], $allowed, true)) {
                return false;
            }
        }

        if ($mark['tool'] === 'text' && (! is_string($mark['text'] ?? null) || mb_strlen($mark['text']) > 120)) {
            return false;
        }

        if (! in_array($mark['tool'], ['text', 'rectangle', 'ellipse'], true) && array_key_exists('text', $mark)) {
            return false;
        }

        if (array_key_exists('text', $mark) && (! is_string($mark['text']) || mb_strlen($mark['text']) > 120)) {
            return false;
        }

        foreach ($mark['points'] as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2) {
                return false;
            }
            foreach ($point as $coordinate) {
                if (! is_int($coordinate) || $coordinate < 0 || $coordinate > 1000) {
                    return false;
                }
            }
        }

        return true;
    }
}
