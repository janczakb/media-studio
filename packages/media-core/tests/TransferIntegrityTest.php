<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Tests;

use PHPUnit\Framework\TestCase;
use UseFlex\MediaCore\Transfers\LocalPartStore;

final class TransferIntegrityTest extends TestCase
{
    public function test_incomplete_part_is_not_accepted_and_tampering_prevents_assembly(): void
    {
        $root = sys_get_temp_dir().'/fms-parts-'.bin2hex(random_bytes(8));
        $session = '01M6VBG4D44PH5CXW68SYX0MWT';
        $store = new LocalPartStore($root);
        $input = tmpfile();
        try {
            fwrite($input, 'abc');
            rewind($input);
            try {
                $store->write($session, 0, $input, 4);
                self::fail('An incomplete part was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Upload part is incomplete.', $error->getMessage());
            }
            self::assertSame([], glob($store->directory($session).'/*'));
            rewind($input);
            $accepted = $store->write($session, 0, $input, 3);
            file_put_contents($store->directory($session).'/0.part', 'bad');
            try {
                $store->assemble($session, [(object) ['part_index' => 0, 'size_bytes' => 3, 'sha256' => $accepted['sha256']]]);
                self::fail('A modified part was assembled.');
            } catch (\RuntimeException $error) {
                self::assertSame('Staged part failed its integrity check.', $error->getMessage());
            }
            self::assertSame([], glob($store->directory($session).'/assembled-*'));
        } finally {
            fclose($input);
            $store->clear($session);
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
}
