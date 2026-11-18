<?php

declare(strict_types=1);

// Interoperability fixtures only. This deliberately PUBLIC test key must never protect real data.
require __DIR__.'/../../vendor/autoload.php';

use UseFlex\MediaCore\Encryption\AesGcmHkdfStream;

$directory = $argv[1] ?? throw new InvalidArgumentException('An isolated output directory is required.');
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create the reference fixture directory.');
}
$key = implode('', array_map(chr(...), range(0, 31)));
foreach ([0, 1, 4040, 4041, 8120, 8121, 20000] as $size) {
    $input = fopen('php://memory', 'w+b');
    $output = fopen($directory.'/'.$size.'.cipher', 'wb');
    $plain = '';
    for ($i = 0; $i < $size; $i++) {
        $plain .= chr(($i * 37) % 256);
    }
    fwrite($input, $plain);
    rewind($input);
    try {
        (new AesGcmHkdfStream($key, 4096))->encrypt($input, $output, 'public-fixture-workspace/version');
        file_put_contents($directory.'/'.$size.'.plain', $plain);
    } finally {
        fclose($input);
        fclose($output);
    }
}
echo "Exported seven public PHP test cases.\n";
