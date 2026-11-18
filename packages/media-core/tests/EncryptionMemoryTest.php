<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UseFlex\MediaCore\Encryption\AesGcmHkdfStream;

final class SyntheticPlaintextStream
{
    public mixed $context;

    private int $position = 0;

    private const SIZE = 268435456;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $count = min($count, self::SIZE - $this->position);
        $this->position += $count;

        return str_repeat('A', $count);
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::SIZE;
    }

    public function stream_stat(): array
    {
        return ['size' => self::SIZE];
    }
}

final class EncryptionMemoryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_256_mib_file_has_bounded_memory_and_constant_tail_io(): void
    {
        stream_wrapper_register('fms-synthetic-test', SyntheticPlaintextStream::class);
        $input = fopen('fms-synthetic-test://public-fixture', 'rb');
        $output = tmpfile();
        $engine = new AesGcmHkdfStream(random_bytes(32), 65536);
        $baseline = memory_get_usage();
        memory_reset_peak_usage();
        $start = hrtime(true);
        try {
            $engine->encrypt($input, $output, 'synthetic-benchmark');
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $baseline;
            $cipherSize = ftell($output);
            $readBytes = 0;
            $reader = $engine->reader(function ($offset, $length) use ($output, &$readBytes) {
                $readBytes += $length;
                fseek($output, $offset);

                return fread($output, $length);
            }, $cipherSize, 'synthetic-benchmark');
            self::assertSame(268435456, $reader->size);
            self::assertSame(str_repeat('A', 1024), $reader->read($reader->size - 1024, 1024));
            self::assertLessThan(4194304, $peak, 'Stream encryption must not grow memory with input size.');
            self::assertLessThanOrEqual(2 * 65536 + 40, $readBytes); // The last 1 KiB may span two adjacent segments.
            if (getenv('FMS_ENCRYPTION_BENCHMARK') === '1') {
                file_put_contents(__DIR__.'/../../../.internal/encryption-benchmark.json', json_encode(['plaintextBytes' => $reader->size, 'ciphertextBytes' => $cipherSize, 'encryptionMs' => round($elapsed, 2), 'peakAdditionalBytes' => $peak, 'tailReadCiphertextBytes' => $readBytes], JSON_THROW_ON_ERROR).PHP_EOL);
            }
        } finally {
            fclose($input);
            fclose($output);
            stream_wrapper_unregister('fms-synthetic-test');
        }
    }
}
