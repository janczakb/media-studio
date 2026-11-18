---
title: "Video thumbnails and playback conversions"
description: "Generate video posters independently of MP4 playback previews, with explicit queue, codec and resource settings."
---

A video poster is a still image used by library cards. A playback conversion is a separate MP4. Neither requires turning on the [video trim editor](/video-editing), and neither replaces the original download.

## Generate a poster without recompressing the video

Set `FLEX_MEDIA_STUDIO_FFMPEG` to the executable available on the processing worker. Configure an asynchronous Laravel queue connection; the shipped video recipe builder intentionally admits no video recipes on `sync`.

```php
// Merge into config/media-studio.php.
'processing' => [
    'connection' => 'redis',
    'queue' => 'media',
    'video' => [
        'enabled' => true,
        'ffmpeg' => env('FLEX_MEDIA_STUDIO_FFMPEG'),
        'poster' => true,
        'poster_edge' => 480,
        'poster_format' => 'webp',
        'poster_quality' => 78,
        'compress' => false,
        'max_source_bytes' => 256 * 1024 * 1024,
        'max_output_bytes' => 256 * 1024 * 1024,
        'strip_metadata' => true,
    ],
],
```

```bash
ffmpeg -encoders
php artisan queue:work redis --queue=media --timeout=120 --tries=3
```

The worker must have `libwebp` for WebP posters or `libaom-av1` for AVIF posters. Verify the actual installed FFmpeg build; a binary's presence alone does not prove codec availability. Images, PDF rendering and video rendering use different encoder paths.

The poster is published under `processing.thumbnails.preset` (default `thumbnail-v1`). Its edge is clamped to 64–1200 pixels and quality to 1–100. The card resolves the ready rendition rather than downloading the full video as a thumbnail.

## Add a smaller playback rendition

Merge these additional settings into `processing.video`:

```php
'compress' => true,
'max_edge' => 1280,
'crf' => 26,
```

The playback output uses MP4/H.264 (`libx264`) and the `processing.preview.preset` role (default `large-v1`). The configured edge is clamped to 320–1920 pixels and CRF to 16–35. Lower CRF favors quality and increases file size. A conversion is not guaranteed to be smaller for every source.

Set `poster => false` if only the MP4 is needed. Set `compress => false` if only the still thumbnail is needed. Set `enabled => false` to stop admitting new video recipes; already generated media is not removed by this switch.

## Recovery and deployment

Video jobs share `processing.connection` and `processing.queue`; `video_editing.queue` belongs to trimming. On S3, original/output objects can remain remote, but FFmpeg still needs local temporary capacity on the processing machine. Do not run decoding merely to display a file list.

Use [rendition reconciliation](/rendition-processing#recover-interrupted-processing) to recover eligible queued/failed work after fixing a missing codec or worker. Image-only `--images` and document-only `--documents` modes do not process video recipes. Check the configured retry count before expecting a failed job to run again.

For every video option and its shipped default, see [processing configuration reference](/configuration-processing#processing). For PDF, Office and text thumbnails, use the separate [document thumbnail guide](/document-thumbnails).
