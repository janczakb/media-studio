<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use PHPUnit\Framework\TestCase;
use UseFlex\MediaStudio\Process\SymfonyProcessExecutor;

final class ProcessExecutorTest extends TestCase
{
    public function test_capture_preserves_literal_arguments_and_returns_only_stdout(): void
    {
        $executor = new SymfonyProcessExecutor;
        $literal = 'file with spaces; $(not-a-shell)';
        $output = $executor->capture([PHP_BINARY, '-r', 'fwrite(STDERR, "private diagnostics"); echo $argv[1];', $literal], 5, 1024);
        self::assertSame($literal, $output);
    }

    public function test_stdout_cannot_exceed_the_capture_budget(): void
    {
        $this->expectExceptionMessage('process_output_limit');
        (new SymfonyProcessExecutor)->capture([PHP_BINARY, '-r', 'echo str_repeat("x", 1024);'], 5, 64);
    }

    public function test_failed_process_does_not_publish_stderr(): void
    {
        $this->expectExceptionMessage('process_failed');
        (new SymfonyProcessExecutor)->capture([PHP_BINARY, '-r', 'fwrite(STDERR, "private diagnostic"); exit(3);'], 5, 1024);
    }

    public function test_file_output_is_bounded_without_buffering_the_file(): void
    {
        $file = tmpfile();
        try {
            $path = stream_get_meta_data($file)['uri'];
            $this->expectExceptionMessage('output_too_large');
            (new SymfonyProcessExecutor)->writeFile([PHP_BINARY, '-r', 'file_put_contents($argv[1], str_repeat("x", 1024));', $path], 5, $path, 64);
        } finally {
            fclose($file);
        }
    }
}
