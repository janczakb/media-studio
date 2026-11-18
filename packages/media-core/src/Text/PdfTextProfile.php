<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Text;

final readonly class PdfTextProfile
{
    public function __construct(public string $infoBinary = 'pdfinfo', public string $textBinary = 'pdftotext', public int $maxPages = 25, public int $timeout = 10, public int $ocrSeconds = 90, public int $ocrPages = 0)
    {
        if ($infoBinary === '' || $textBinary === '' || str_contains($infoBinary.$textBinary, "\0") || $maxPages < 1 || $maxPages > 100 || $timeout < 1 || $timeout > 30 || $ocrSeconds < 1 || $ocrSeconds > 300 || $ocrPages < 0 || $ocrPages > 25) {
            throw new \InvalidArgumentException('Invalid PDF extraction profile.');
        }
    }
}
