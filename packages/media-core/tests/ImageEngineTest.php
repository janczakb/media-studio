<?php

declare(strict_types=1);

namespace UseFlex\MediaCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UseFlex\MediaCore\Images\EncodedImageVerifier;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImageGeometry;
use UseFlex\MediaCore\Images\ImagePreset;

final class ImageEngineTest extends TestCase
{
    private function preset(string $fit): ImagePreset
    {
        return new ImagePreset('thumb', ['width' => 400, 'height' => 400, 'fit' => $fit, 'format' => 'webp', 'quality' => 80, 'upscale' => false, 'background' => '#ffffff', 'position' => 'center']);
    }

    public static function geometries(): iterable
    {
        yield 'landscape' => [1600, 800, 'contain', 400, 200];
        yield 'portrait' => [800, 1600, 'contain', 200, 400];
        yield 'no enlargement' => [100, 50, 'contain', 100, 50];
        yield 'center crop' => [1600, 800, 'cover', 400, 400];
        yield 'padded canvas' => [1600, 800, 'pad', 400, 400];
    }

    #[DataProvider('geometries')]
    public function test_geometry_preserves_existing_fit_and_upscale_contract(int $width, int $height, string $fit, int $expectedWidth, int $expectedHeight): void
    {
        $geometry = ImageGeometry::forPreset($width, $height, $this->preset($fit));
        self::assertSame([$expectedWidth, $expectedHeight], array_slice($geometry, 0, 2));
    }

    public function test_real_renderer_uses_explicit_budget_and_verifies_actual_bytes(): void
    {
        if (! GdImageRenderer::supports('webp')) {
            self::markTestSkipped('GD WebP encoder required.');
        }
        $source = imagecreatetruecolor(80, 40);
        $output = tmpfile();
        try {
            $dimensions = (new GdImageRenderer(67108864))->render($source, $this->preset('contain'), $output);
            self::assertSame(['width' => 80, 'height' => 40], $dimensions);
            (new EncodedImageVerifier)->verify($output, 'image/webp', $dimensions);
            self::assertGreaterThan(0, fstat($output)['size']);
            $this->expectException(\RuntimeException::class);
            (new EncodedImageVerifier)->verify($output, 'image/avif', $dimensions);
        } finally {
            fclose($output);
        }
    }

    public function test_memory_budget_is_enforced_without_reading_laravel_config(): void
    {
        if (! GdImageRenderer::supports('webp')) {
            self::markTestSkipped('GD WebP encoder required.');
        }
        $output = tmpfile();
        try {
            $this->expectExceptionMessage('image_memory_limit');
            (new GdImageRenderer(1))->render(imagecreatetruecolor(80, 40), $this->preset('contain'), $output);
        } finally {
            fclose($output);
        }
    }
}
