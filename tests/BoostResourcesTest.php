<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UseFlex\MediaStudio\Library\UploadAsset;

final class BoostResourcesTest extends TestCase
{
    public function test_skill_entrypoint_and_progressive_references_ship_inside_the_laravel_package(): void
    {
        $base = dirname(__DIR__).'/packages/laravel/resources/boost';
        $skill = $base.'/skills/media-studio-development';
        $content = file_get_contents($skill.'/SKILL.md');
        self::assertMatchesRegularExpression('/\A---\nname: media-studio-development\ndescription: [^\n]+\n---/', $content);
        preg_match_all('/\]\((references\/[^)]+)\)/', $content, $matches);
        self::assertCount(3, $matches[1]);
        foreach ($matches[1] as $reference) {
            self::assertFileExists($skill.'/'.$reference);
            self::assertGreaterThan(500, filesize($skill.'/'.$reference));
        }
        self::assertStringContainsString('media-studio-development', file_get_contents($base.'/guidelines/core.blade.php'));
    }

    public function test_context_uses_the_installed_contract_without_host_paths_or_mandatory_boost_runtime_dependency(): void
    {
        $root = dirname(__DIR__).'/packages/laravel';
        $composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('laravel/boost', $composer['require']);
        self::assertArrayHasKey('laravel/boost', $composer['suggest']);
        $skill = file_get_contents($root.'/resources/boost/skills/media-studio-development/SKILL.md');
        self::assertStringContainsString('InstalledVersions::getInstallPath', $skill);
        self::assertStringNotContainsString('/Users/', $skill);
        self::assertStringNotContainsString('flex-media-studio.test', $skill);
        $parameters = (new ReflectionMethod(UploadAsset::class, 'execute'))->getParameters();
        self::assertSame(['workspace', 'principal', 'parentId', 'file', 'key'], array_map(fn ($parameter) => $parameter->getName(), array_slice($parameters, 0, 5)));
    }
}
