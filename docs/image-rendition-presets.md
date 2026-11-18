---
title: "Image conversion presets"
description: "Configure resizing, cropping, formats, metadata and overrides per model."
---

Use Studio-native conversions for files the application wants Studio to process. For pre-generated files, use [external renditions](/external-renditions).

## Resize, crop and format

| Setting | Options |
| --- | --- |
| `width`, `height` | Output bounds from 1 to 4096 pixels |
| `fit` | `contain` preserves the entire image, `cover` crops to the target ratio, `pad` adds background to fill the target, `stretch` uses the exact ratio |
| `position` | `center`, `top`, `bottom`, `left`, `right` for `cover` cropping |
| `format` | `webp`, `avif`, subject to the selected driver's available encoders |
| `quality` | 1–100; default profile uses 40/40/50 |
| `avif_effort` | 0–9; defaults to 3 for new Studio image conversions |
| `upscale` | Default `false`, keeping small source images small |
| `background` | RGB hex color, default `#ffffff`, used for padding |
| `disk` | Optional output disk. Omit it to use the original's disk |

Choose `gd` or `imagick` with `processing.native.driver`. The default `auto`
prefers GD and uses ImageMagick when it can cover a format missing from GD.
JPEG orientation is normalized before resizing. Enable the formats you use in the selected server encoder.
An unavailable encoder reports a failed variant while successful variants remain
available. Source byte, pixel and estimated working-memory limits constrain
processing. Configure at most 16 native variants per source.

The recipe is saved with the source version when the job is scheduled. This keeps
an in-flight upload consistent with its configuration. Use a new name such as
`card-v2` when you want a new output recipe. New settings apply to new uploads.
Existing outputs keep their recorded recipes until you explicitly regenerate them.

## Keep or remove embedded metadata

Set `strip_metadata` globally for Studio image conversions. It defaults to
`true`. Each named conversion can override it.

```php
// config/media-studio.php, inside processing
'native' => [
    'enabled' => true,
    'driver' => 'auto',
    'strip_metadata' => true,
],
'renditions' => [
    'thumbnail-v1' => [
        'width' => 480,
        'height' => 480,
        'format' => 'webp',
        'quality' => 78,
    ],
    'preview-v1' => [
        'width' => 1600,
        'height' => 1600,
        'format' => 'avif',
        'quality' => 65,
        'strip_metadata' => false,
    ],
],
```

When enabled, cleanup removes embedded profiles and comments from that
conversion. When disabled, ImageMagick preserves supported metadata such as
EXIF author and XMP, including profiles when padding the image. Image orientation
is normalized before writing the output. The selected encoder determines which
metadata the output format supports.

Metadata preservation requires ImageMagick. With `driver => 'auto'`, Studio
selects it when needed. An explicit GD configuration with
`strip_metadata => false` reports `metadata_requires_imagick` for that variant.
The original uploaded file retains its original bytes and metadata.

For optional video processing, use
`processing.video.strip_metadata` (default `true`). Setting it to `false`
maps the input's supported container metadata to the generated playback file
and poster. Each output format determines which tags it supports.

## Use different conversions for a model

Set `processing.model_renditions` in Laravel configuration. Each model's list
replaces the global preset list for uploads associated with that model.

```php
// config/media-studio.php, inside processing
'model_renditions' => [
    App\Models\Post::class => [
        'thumbnail-v1' => [
            'width' => 480,
            'height' => 320,
            'fit' => 'cover',
            'format' => 'webp',
            'quality' => 75,
        ],
        'preview-v1' => [
            'width' => 1600,
            'height' => 1000,
            'format' => 'avif',
            'quality' => 65,
            'strip_metadata' => true,
        ],
    ],
],
```

Pass the owning model from your authorized Laravel upload handler.

```php
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\UploadAsset;

$principal = Principal::fromUser($request->user());
$workspace = app(WorkspaceAccess::class)->resolve(
    $principal,
    $workspaceId,
    write: true,
);

$entry = app(UploadAsset::class)->execute(
    workspace: $workspace,
    principal: $principal,
    parentId: null,
    file: $request->file('image'),
    key: (string) Illuminate\Support\Str::uuid(),
    owner: $post,
);
```

Authorize access to `$post` in your handler before calling the upload service.
The model class is saved with the source version, so a later **Optimize image**
uses that model's current conversion configuration. Each queued job captures its
own preset snapshot.

## Use a named variant in content

Store the asset ID, optional version ID and rendition name together. Resolve
references through Studio to get the authenticated URL.

```php
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\MediaReferences;

[$image] = app(MediaReferences::class)->resolve(
    Principal::fromUser(auth()->user()),
    $workspaceId,
    [[
        'assetId' => $assetId,
        'versionId' => $versionId,
        'rendition' => 'article-v1',
    ]],
);

// $image['url'] delivers the selected ready rendition.
// $image['mimeType'] reports the rendition's format.
```

A requested variant must be ready and belong to the selected current asset
version. Pass many references together to resolve them in a batch. When
[registering usages](/configuration#store-references-in-your-own-application),
include the same `rendition` name in each reference.
