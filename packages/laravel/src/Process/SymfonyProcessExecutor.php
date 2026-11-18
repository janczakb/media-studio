<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Process;

use RuntimeException;
use Symfony\Component\Process\Process;
use UseFlex\MediaCore\Process\ProcessExecutor;

final class SymfonyProcessExecutor implements ProcessExecutor
{
    public function capture(array $arguments, int $timeout, int $maxOutput, array $environment = []): string
    {
        if ($arguments === [] || $timeout < 1 || $maxOutput < 1) {
            throw new \InvalidArgumentException('Invalid process limits.');
        }
        $process = new Process($arguments, null, $environment ?: null, timeout: $timeout);
        $output = '';
        $diagnostics = 0;
        try {
            $process->run(function (string $type, string $chunk) use ($process, &$output, &$diagnostics, $maxOutput): void {
                if ($type === Process::OUT) {
                    if (strlen($output) + strlen($chunk) > $maxOutput) {
                        throw new RuntimeException('process_output_limit');
                    }
                    $output .= $chunk;
                    $process->clearOutput();
                } else {
                    $diagnostics += strlen($chunk);
                    $process->clearErrorOutput();
                    if ($diagnostics > 65536) {
                        throw new RuntimeException('process_diagnostic_limit');
                    }
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('process_failed');
            }

            return $output;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public function writeFile(array $arguments, int $timeout, string $destination, int $maximum, string $failure = 'encoding_failed'): void
    {
        if ($arguments === [] || $timeout < 1 || $maximum < 1) {
            throw new \InvalidArgumentException('Invalid process limits.');
        }
        $process = new Process($arguments, timeout: $timeout);
        $process->disableOutput();
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                clearstatcache(true, $destination);
                if ((@filesize($destination) ?: 0) > $maximum) {
                    throw new RuntimeException('output_too_large');
                }
                usleep(50000);
            }
            clearstatcache(true, $destination);
            $size = @filesize($destination);
            if ($size !== false && $size > $maximum) {
                throw new RuntimeException('output_too_large');
            }
            if (! $process->isSuccessful() || $size === false || $size < 1) {
                throw new RuntimeException($failure);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
