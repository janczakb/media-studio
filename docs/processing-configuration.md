---
title: "Configure processing and workers"
description: "Configure queues and per-workspace processing controls."
---

This page covers configuration. For worker deployment and task scheduling, see [Queues and scheduled maintenance](/queues-and-scheduler).

## Image thumbnails and workers

Thumbnails are separate files. Generating a thumbnail never overwrites its
original. The grid uses the small rendition when it is ready; it does not download
every full-size original to display a list.

```php
'processing' => [
    'connection' => 'database', // Or your application's Redis queue connection.
    'queue' => 'media',
    'thumbnails' => [
        'enabled' => true,
        'edge' => 480,
        'max_pixels' => 16_000_000,
        'max_source_bytes' => 25 * 1024 * 1024,
    ],
],
```

Configure the selected Laravel queue connection and run its worker:

```bash
php artisan queue:work database --queue=media --tries=3 --timeout=120
```

With `connection => null`, Studio uses the application's default queue connection.
A synchronous connection processes in the request; use a background worker when
you want processing to continue independently. Processing status is persisted and
can be refreshed after reopening the library.

The native image renderer supports GD and ImageMagick through
`processing.native.driver` (`auto`, `gd`, `imagick`). Codec availability determines
which WebP/AVIF inputs and outputs can be processed. Native supported inputs are
JPEG, PNG, WebP and AVIF; JPEG orientation is normalized. Images are not enlarged
unless the selected recipe explicitly enables upscaling. Metadata preservation
requires ImageMagick; [image recipes](/image-rendition-presets) explain the exact
selection and failure behavior.

## Select the right processing pipeline

| Input | Configuration and requirements | Guide |
| --- | --- | --- |
| Images | Native image recipes; GD/ImageMagick and selected codec | [Image presets](/image-rendition-presets) |
| PDF | First page; Poppler or PDF-enabled libvips; disabled by default | [Document thumbnails](/document-thumbnails) |
| DOCX/XLSX/PPTX/ODF | Explicit MIME registration, LibreOffice and isolated conversion worker | [Office thumbnails](/document-thumbnails#office-documents) |
| TXT/Markdown/HTML | Explicit MIME registration and local Unicode font; text-only rendering | [Text thumbnails](/document-thumbnails#text-markdown-and-text-only-html) |
| Video | FFmpeg poster and optional MP4; asynchronous connection; disabled by default | [Video thumbnails](/video-thumbnails) |
| Audio | FFmpeg waveform job on its own queue | [Audio previews](/audio-previews) |
| Application-owned files | Separate bounded thumbnail cache; disabled by default | [Application thumbnails](/application-files-thumbnails) |

Document generation has independent command/queue/inline controls. Enabling PDF
text extraction or browser document preview does not enable PDF thumbnails.
Likewise, enabling video trimming does not enable video posters. Consult the
[complete settings reference](/configuration-processing) and
[capability matrix](/configuration-reference) before configuring workers.
