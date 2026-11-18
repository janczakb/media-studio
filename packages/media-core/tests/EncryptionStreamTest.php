<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Tests;

use PHPUnit\Framework\TestCase;
use UseFlex\MediaCore\Encryption\AesGcmHkdfStream;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

final class EncryptionStreamTest extends TestCase
{
    private function encrypted(string $plain, string $key, string $aad = 'workspace/version'): string
    {
        $input = fopen('php://memory', 'w+b');
        $output = fopen('php://memory', 'w+b');
        try {
            fwrite($input, $plain);
            rewind($input);
            (new AesGcmHkdfStream($key, 4096))->encrypt($input, $output, $aad);
            rewind($output);

            return stream_get_contents($output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function test_sizes_boundaries_and_random_access(): void
    {
        $key = random_bytes(32);
        foreach ([0, 1, 4040, 4041, 8120, 8121, 25000] as $size) {
            $plain = $size === 0 ? '' : random_bytes($size);
            $cipher = $this->encrypted($plain, $key);
            $reads = [];
            $reader = (new AesGcmHkdfStream($key, 4096))->reader(function ($offset, $length) use ($cipher, &$reads) {
                $reads[] = [$offset, $length];

                return substr($cipher, $offset, $length);
            }, strlen($cipher), 'workspace/version');
            self::assertSame($size, $reader->size);
            $reader->verifyEnd();
            self::assertSame(substr($plain, 0, 4096), $reader->read(0, 4096));
            if ($size > 0) {
                foreach ([0, intdiv($size, 2), max(0, $size - 4000)] as $start) {
                    self::assertSame(substr($plain, $start, 4000), $reader->read($start, 4000));
                }
            }
            self::assertLessThanOrEqual(4096, max(array_column($reads, 1)));
            self::assertNotSame($cipher, $this->encrypted($plain, $key));
        }
    }

    public function test_wrong_key_context_header_and_ciphertext_fail_closed(): void
    {
        $key = random_bytes(32);
        $cipher = $this->encrypted(str_repeat('x', 18000), $key);
        $variants = [[$cipher, random_bytes(32), 'workspace/version'], [$cipher, $key, 'different/workspace']];
        foreach ([0, 1, 33, 40, 4050, strlen($cipher) - 1] as $position) {
            $bad = $cipher;
            $bad[$position] = chr(ord($bad[$position]) ^ 1);
            $variants[] = [$bad, $key, 'workspace/version'];
        }
        $variants[] = [substr($cipher, 0, 4096), $key, 'workspace/version'];
        $variants[] = [substr($cipher, 0, -1), $key, 'workspace/version'];
        $variants[] = [$cipher.str_repeat('x', 16), $key, 'workspace/version'];
        // Swap complete segments without changing the length.
        $variants[] = [substr($cipher, 0, 4096).substr($cipher, 8192, 4096).substr($cipher, 4096, 4096).substr($cipher, 12288), $key, 'workspace/version'];
        foreach ($variants as [$bytes, $testKey, $aad]) {
            try {
                $reader = (new AesGcmHkdfStream($testKey, 4096))->reader(fn ($start, $length) => substr($bytes, $start, $length), strlen($bytes), $aad);
                $reader->verifyEnd();
                for ($offset = 0; $offset < $reader->size; $offset += 4096) {
                    $reader->read($offset, 4096);
                }
                self::fail('Corrupted ciphertext was accepted.');
            } catch (EncryptionFailure) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_small_tail_read_does_not_read_the_file_prefix(): void
    {
        $key = random_bytes(32);
        $cipher = $this->encrypted(str_repeat('data', 100000), $key);
        $requested = [];
        $reader = (new AesGcmHkdfStream($key, 4096))->reader(function ($offset, $length) use ($cipher, &$requested) {
            $requested[] = [$offset, $length];

            return substr($cipher, $offset, $length);
        }, strlen($cipher), 'workspace/version');
        self::assertSame('data', $reader->read($reader->size - 4, 4));
        self::assertCount(2, $requested);
        self::assertSame([0, 40], $requested[0]);
        self::assertGreaterThan(390000, $requested[1][0]);
        self::assertLessThan(4200, array_sum(array_column($requested, 1)));
    }

    public function test_secret_material_is_not_debugged_or_serialized(): void
    {
        $key = random_bytes(32);
        $engine = new AesGcmHkdfStream($key);
        ob_start();
        var_dump($engine);
        $dump = ob_get_clean();
        self::assertStringNotContainsString($key, $dump);
        $this->expectException(EncryptionFailure::class);
        serialize($engine);
    }

    public function test_reads_real_google_tink_reference_vectors(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/tink-aes256-gcm-hkdf.json'), true, flags: JSON_THROW_ON_ERROR);
        $key = hex2bin($fixture['publicTestKey']);
        foreach ($fixture['vectors'] as $vector) {
            $cipher = base64_decode($vector['ciphertext'], true);
            $reader = (new AesGcmHkdfStream($key, 4096))->reader(fn ($start, $length) => substr($cipher, $start, $length), strlen($cipher), $fixture['associatedData']);
            $expected = '';
            for ($i = 0; $i < $vector['size']; $i++) {
                $expected .= chr(($i * 37) % 256);
            }
            $reader->verifyEnd();
            $actual = '';
            for ($offset = 0; $offset < $reader->size; $offset += 4096) {
                $actual .= $reader->read($offset, 4096);
            }
            self::assertSame($expected, $actual);
        }
    }
}
