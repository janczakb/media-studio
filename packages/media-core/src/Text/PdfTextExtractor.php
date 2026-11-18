<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Text;

use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;

/** Native text first; optional bounded OCR only for pages without extracted text. */
final class PdfTextExtractor
{
    public function __construct(private readonly ProcessExecutor $processes, private readonly PdfTextProfile $profile) {}

    public function extract(string $input, int $characters, ?callable $ocrPage = null): array
    {
        $pages = $this->profile->maxPages;
        $timeout = $this->profile->timeout;
        if ($characters < 1 || ! str_starts_with($input, '%PDF-')) {
            throw new RuntimeException('Invalid PDF or extraction limits.');
        }
        $source = tmpfile();
        if (! is_resource($source)) {
            throw new RuntimeException('PDF staging unavailable.');
        }
        try {
            $path = stream_get_meta_data($source)['uri'];
            chmod($path, 0600);
            if (fwrite($source, $input) !== strlen($input) || ! fflush($source)) {
                throw new RuntimeException('PDF staging failed.');
            }
            $info = $this->processes->capture([$this->profile->infoBinary, $path], $timeout, 65536, ['LC_ALL' => 'C']);
            if (! preg_match('/^Pages:\s+(\d+)\s*$/m', $info, $match)) {
                throw new RuntimeException('PDF page count unavailable.');
            }
            $total = (int) $match[1];
            if ($total < 1 || preg_match('/^Encrypted:\s+yes\b/m', $info)) {
                throw new RuntimeException('Encrypted or invalid PDF is not supported.');
            }
            $selected = min($pages, $total);
            $text = $this->processes->capture([$this->profile->textBinary, '-f', '1', '-l', (string) $selected, '-enc', 'UTF-8', '-eol', 'unix', $path, '-'], $timeout, 262144, ['LC_ALL' => 'C']);
            $parts = explode("\f", $text);
            if (end($parts) === '') {
                array_pop($parts);
            }
            $deadline = hrtime(true) + $this->profile->ocrSeconds * 1000000000;
            $ocrPages = 0;
            $ocrLimit = $this->profile->ocrPages;
            $used = 0;
            foreach ($parts as $index => &$part) {
                if ($used >= $characters) {
                    break;
                }
                if ($ocrPage !== null && trim($part) === '' && $ocrPages < $ocrLimit && hrtime(true) < $deadline) {
                    $part = $ocrPage($path, $index + 1);
                    $ocrPages++;
                }
                $used += mb_strlen($part);
            }
            unset($part);
            $text = implode("\n", $parts);
            $empty = count(array_filter($parts, static fn (string $part): bool => trim($part) === ''));
            $result = (new TextExtractor)->extract(str_replace("\f", "\n", $text), 'text/plain', $characters);
            $result['partial'] = $result['partial'] || $total > $selected || $empty > 0;
            $result['state'] = $result['text'] === '' ? 'no_text' : ($result['partial'] ? 'partial' : 'ready');
            $result['pages'] = $total;
            $result['processedPages'] = $selected;
            $result['emptyTextPages'] = $empty;
            $result['ocrPages'] = $ocrPages;

            return $result;
        } finally {
            fclose($source);
        }
    }
}
