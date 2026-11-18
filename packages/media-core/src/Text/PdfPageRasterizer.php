<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Text;

use UseFlex\MediaCore\Process\ProcessExecutor;

final readonly class PdfPageRasterizer
{
    public function __construct(private ProcessExecutor $processes, private string $binary = 'pdftoppm', private int $edge = 2048, private int $timeout = 15) {}

    public function jpeg(string $source, int $page): string
    {
        if ($this->edge < 512 || $this->timeout > 30) {
            throw new \InvalidArgumentException('Invalid PDF OCR limits.');
        }

        return $this->processes->capture([...$this->arguments($page), '-jpeg', '-jpegopt', 'quality=85', $source], $this->timeout, 4194304);
    }

    public function png(string $source, int $page, int $maximum): string
    {
        return $this->processes->capture(
            [...$this->arguments($page), '-png', $source],
            $this->timeout,
            $maximum,
        );
    }

    public function pngFile(string $source, int $page, string $outputPrefix, int $maximum): void
    {
        $this->processes->writeFile(
            [...$this->arguments($page), '-png', $source, $outputPrefix],
            $this->timeout,
            $outputPrefix.'.png',
            $maximum,
            'document_render_failed',
        );
    }

    /** @return list<string> */
    private function arguments(int $page): array
    {
        if ($this->binary === '' || str_contains($this->binary, "\0") || $page < 1 || $page > 100 || $this->edge < 1 || $this->edge > 4096 || $this->timeout < 1 || $this->timeout > 90) {
            throw new \InvalidArgumentException('Invalid PDF rasterization limits.');
        }

        return [$this->binary, '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', (string) $this->edge];
    }
}
