---
title: "Image conversions and existing thumbnails"
---

Media Studio can generate its own image variants, show conversions from Spatie
Media Library, or reuse thumbnails produced by your application. Choose which
pipeline owns each file's conversions. The file list displays a thumbnail and
opens a larger preview when you select the file. When no conversion exists,
the grid shows a file icon and the full viewer opens the original image.

## Generate variants with Studio

The default image profile creates three AVIF conversions, preserving aspect ratio:

| Conversion | Maximum longest edge | AVIF quality |
| --- | --- | --- |
| `thumbnail-v1` | 400 px | 40 |
| `medium-v1` | 1000 px | 40 |
| `large-v1` | 1900 px | 50 |

Small images are not enlarged. Metadata is stripped by default. The viewer uses
`large-v1`; each conversion is independently configurable. Originals are retained
by default. The worker decodes the source once for the complete batch. AVIF
requires an available server encoder; no silent JPEG/WebP fallback is performed.
Quality values are configurable and should be reviewed against your own images.

Add named variants in `config/media-studio.php`.

```php
'processing' => [
    'connection' => 'database',
    'queue' => 'images',
    'native' => [
        'enabled' => true,
        'driver' => 'auto',
        'max_attempts' => 3,
        'max_working_bytes' => 256 * 1024 * 1024,
    ],
    'thumbnails' => [
        'enabled' => true,
        'preset' => 'card-v1',
        'max_pixels' => 16_000_000,
        'max_source_bytes' => 25 * 1024 * 1024,
    ],
    'preview' => ['enabled' => true, 'preset' => 'viewer-v1'],
    'renditions' => [
        'card-v1' => [
            'width' => 480,
            'height' => 360,
            'fit' => 'cover',
            'position' => 'center',
            'format' => 'webp',
            'quality' => 78,
            'upscale' => false,
        ],
        'viewer-v1' => [
            'width' => 1600,
            'height' => 1600,
            'fit' => 'contain',
            'format' => 'webp',
            'quality' => 84,
        ],
        'article-v1' => [
            'width' => 1200,
            'height' => 800,
            'fit' => 'contain',
            'format' => 'avif',
            'quality' => 88,
            'background' => '#ffffff',
            'disk' => 'article-images',
        ],
    ],
],
```

Run the selected queue worker in the application.

```bash
php artisan queue:work database --queue=images --tries=3 --timeout=120
```

With `connection => null`, Studio uses the application's default connection.
A synchronous connection generates variants during the upload request. Use a
background connection to move that work out of the request. Pending variants
become available automatically while the library is open and after reopening it.

## Resize, crop and format

[Continue to Image conversion presets](/image-rendition-presets#resize-crop-and-format).

## Keep or remove embedded metadata

[Continue to Image conversion presets](/image-rendition-presets#keep-or-remove-embedded-metadata).

## Use different conversions for a model

[Continue to Image conversion presets](/image-rendition-presets#use-different-conversions-for-a-model).

## Optimize images already in Studio

[Continue to Optimize and recover media processing](/rendition-processing#optimize-images-already-in-studio).

## Generate video posters and playback previews

[Continue to Optimize and recover media processing](/rendition-processing#generate-video-posters-and-playback-previews).

## Use a named variant in content

[Continue to Image conversion presets](/image-rendition-presets#use-a-named-variant-in-content).

## Reuse your application's generated thumbnails

[Continue to Use existing thumbnails and conversions](/external-renditions#reuse-your-application’s-generated-thumbnails).

## Reuse Spatie conversions

[Continue to Use existing thumbnails and conversions](/external-renditions#reuse-spatie-conversions).

## Reuse FLEX Fields conversions

[Continue to Use existing thumbnails and conversions](/external-renditions#reuse-flex-fields-conversions).

## Recover interrupted processing

[Continue to Optimize and recover media processing](/rendition-processing#recover-interrupted-processing).

## Supply display URLs from a custom frontend transport

[Continue to Use existing thumbnails and conversions](/external-renditions#supply-display-urls-from-a-custom-frontend-transport).

## Keep or remove the uploaded original

[Continue to Originals and edited versions](/media-versions#keep-or-remove-the-uploaded-original).

### AVIF compression effort

[Continue to Originals and edited versions](/media-versions#avif-compression-effort).

## Share identical small-image outputs

[Continue to Optimize and recover media processing](/rendition-processing#share-identical-small-image-outputs).

### Verified AVIF output

[Continue to Optimize and recover media processing](/rendition-processing#verified-avif-output).

## Generate images without a permanent queue worker

[Continue to Optimize and recover media processing](/rendition-processing#generate-images-without-a-permanent-queue-worker).

## Deliver variants through a CDN

Use [CDN delivery profiles](/cdn-delivery) to choose providers for thumbnails,
previews and named presets. Conversion storage and delivery are independent;
you do not need to regenerate a native or Spatie conversion to change its
configured delivery profile.
