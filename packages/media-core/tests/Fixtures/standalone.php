<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap.php';

use UseFlex\MediaCore\Images\ImageGeometry;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaCore\Storage\ObjectStore;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaCore\Transfers\LocalPartStore;

$check = static function (bool $value): void {
    if (! $value) {
        throw new RuntimeException('Standalone contract failed.');
    }
};
$check(! function_exists('app') && ! function_exists('config') && ! class_exists('Illuminate\\Container\\Container'));
$preset = new ImagePreset('thumb', ['width' => 400, 'height' => 400, 'fit' => 'contain', 'format' => 'avif', 'quality' => 40, 'upscale' => false, 'background' => '#ffffff', 'position' => 'center']);
$geometry = ImageGeometry::forPreset(1600, 800, $preset);
$check($geometry[0] === 400 && $geometry[1] === 200);
$store = new class implements ObjectStore
{
    public string $bytes = '';

    public function writePrivateStream(string $key, mixed $stream): bool
    {
        $this->bytes = stream_get_contents($stream);

        return true;
    }
};
$stream = tmpfile();
fwrite($stream, 'original bytes');
rewind($stream);
(new StreamObjectStore($store))->write('uploads/version_1', $stream);
$check($store->bytes === 'original bytes' && is_resource($stream));
fclose($stream);
$root = sys_get_temp_dir().'/fms-core-'.bin2hex(random_bytes(8));
$parts = new LocalPartStore($root);
$session = '01M6VBG4D44PH5CXW68SYX0MWT';
try {
    $input = tmpfile();
    $chunk = str_repeat('x', 65536);
    for ($index = 0; $index < 128; $index++) {
        fwrite($input, $chunk);
    }
    $expectedHash = hash_file('sha256', stream_get_meta_data($input)['uri']);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    rewind($input);
    $result = $parts->write($session, 0, $input, 8388608);
    fclose($input);
    $check($result['size'] === 8388608 && $result['sha256'] === $expectedHash);
    $path = $parts->assemble($session, [(object) ['part_index' => 0, 'size_bytes' => 8388608, 'sha256' => $result['sha256']]]);
    $check(hash_file('sha256', $path) === $expectedHash);
    $check(memory_get_peak_usage(true) - $baseline < 4194304);
} finally {
    $parts->clear($session);
    if (is_dir($root)) {
        rmdir($root);
    }
}
$check(! class_exists('Illuminate\\Container\\Container'));
echo "standalone-ok\n";
