<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Tests;

use PHPUnit\Framework\TestCase;

final class CoreBoundaryTest extends TestCase
{
    public function test_core_executes_with_no_composer_or_framework_bootstrap(): void
    {
        $process = proc_open([PHP_BINARY, '-n', __DIR__.'/Fixtures/standalone.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error);
        self::assertSame("standalone-ok\n", $output);
    }

    public function test_core_has_no_framework_imports_or_global_service_lookups(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src'));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = file_get_contents($file->getPathname());
            foreach (['Illuminate\\', 'Filament\\', 'Livewire\\', 'UseFlex\\MediaStudio\\'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $text, $file->getPathname());
            }
            $tokens = token_get_all($text);
            foreach ($tokens as $index => $token) {
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (! in_array($name, ['app', 'config', 'abort', 'collect', 'env', 'storage_path', 'response', 'now'], true)) {
                    continue;
                }
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }
                self::assertNotSame('(', $tokens[$next] ?? null, $file->getPathname().': '.$name);
            }
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
        $manifest = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['php' => '^8.3'], $manifest['require']);
    }
}
