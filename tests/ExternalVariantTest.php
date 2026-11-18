<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UseFlex\MediaStudio\Renditions\ExternalVariant;

final class ExternalVariantTest extends TestCase
{
    public function test_private_storage_identity_and_optional_metadata_are_retained(): void
    {
        $hash = hash('sha256', 'existing image');
        $variant = new ExternalVariant('card', 'private-assets', 'receipts/a/card.avif', 'image/avif', 32768, 640, 480, $hash);

        $this->assertSame('receipts/a/card.avif', $variant->objectKey);
        $this->assertSame('private-assets', $variant->disk);
        $this->assertSame($hash, $variant->sha256);
        $this->assertSame(640, $variant->width);
        $this->assertSame(480, $variant->height);
        $this->assertSame(str_repeat('a', 64), (new ExternalVariant(str_repeat('a', 64), 'private-assets', 'card.avif', 'image/avif', 1))->name);
        $this->assertNull((new ExternalVariant('poster', 'private-assets', 'posters/a.jpg', 'image/jpeg', 1))->width);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_provider_metadata_is_rejected(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExternalVariant(...array_replace(['name' => 'card', 'disk' => 'private', 'objectKey' => 'card.avif',
            'mimeType' => 'image/avif', 'sizeBytes' => 100], $values));
    }

    public static function invalidDefinitions(): iterable
    {
        yield 'empty preset' => [['name' => '']];
        yield 'long preset' => [['name' => str_repeat('a', 65)]];
        yield 'empty disk' => [['disk' => '']];
        yield 'control disk' => [['disk' => "private\nother"]];
        yield 'absolute object' => [['objectKey' => '/private/card.avif']];
        yield 'parent traversal' => [['objectKey' => 'a/../card.avif']];
        yield 'current traversal' => [['objectKey' => 'a/./card.avif']];
        yield 'empty segment' => [['objectKey' => 'a//card.avif']];
        yield 'trailing slash' => [['objectKey' => 'a/']];
        yield 'URL object' => [['objectKey' => 'https://private/card.avif']];
        yield 'windows object' => [['objectKey' => 'C:/private/card.avif']];
        yield 'backslash object' => [['objectKey' => 'a\\card.avif']];
        yield 'invalid MIME' => [['mimeType' => "image/avif\r\nX-Foo: bar"]];
        yield 'negative bytes' => [['sizeBytes' => -1]];
        yield 'zero dimension' => [['width' => 0]];
        yield 'negative dimension' => [['height' => -1]];
        yield 'oversized dimension' => [['width' => 4294967296]];
        yield 'invalid digest' => [['sha256' => 'not-a-digest']];
    }
}
