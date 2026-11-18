<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Text;

final class TextExtractor
{
    public const MIMES = ['text/plain', 'text/markdown', 'text/csv', 'text/html'];

    /** MIME aliases are explicit: a .md extension never turns binary input into text. */
    public function mime(string $mime, string $name): ?string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($mime, ['text/markdown', 'text/x-markdown'], true)) {
            return 'text/markdown';
        }
        if ($mime === 'text/plain') {
            return in_array($extension, ['html', 'htm'], true) ? 'text/html' : 'text/plain';
        }

        return match ($mime) {
            'text/csv', 'application/csv', 'text/tab-separated-values' => 'text/csv',
            'text/html' => 'text/html',
            default => null,
        };
    }

    public function extract(string $input, string $mime, int $maxCharacters): array
    {
        if ($maxCharacters < 1) {
            throw new \InvalidArgumentException('Text output limit must be positive.');
        }
        if (! in_array($mime, self::MIMES, true) || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            throw new \RuntimeException('Unsupported text encoding or format.');
        }
        if ($mime === 'text/html') {
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $dom->loadHTML('<?xml encoding="UTF-8">'.$input, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//script|//style|//iframe|//object|//svg|//template|//head') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                foreach ($xpath->query('//p|//div|//br|//li|//tr|//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
                    $node->appendChild($dom->createTextNode("\n"));
                }
                $input = $dom->textContent;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input) ?? '';
        $input = trim(preg_replace('/\s+/u', ' ', $input) ?? '');
        $partial = mb_strlen($input) > $maxCharacters;

        return ['text' => mb_substr($input, 0, $maxCharacters), 'partial' => $partial];
    }
}
