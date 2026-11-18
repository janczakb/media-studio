---
title: Audio previews and waveforms
description: Play authorized audio, configure waveform generation and backfill existing files.
---

Open an audio file in the viewer and use the playback controls. The browser must support the file's codec. The waveform is generated separately for eligible managed files. Audio playback and original delivery remain available when a waveform is missing, provided the file is accessible and its codec is supported.

## Configure waveform generation

Merge this fragment under `processing` in `config/media-studio.php`. This example assumes Redis is an available Laravel queue connection.

```php
'audio_waveforms' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'audio-waveforms',
    'ffmpeg' => env('FLEX_MEDIA_STUDIO_FFMPEG'),
    'max_source_bytes' => 128 * 1024 * 1024,
    'max_seconds' => 7200,
],
```

Verify that FFmpeg is available on the machine running the job. Upload a short audio file with an allowed MIME type. Once playback works, wait for the waveform and reload the preview. If seeking fails, inspect the authenticated media request and the proxy’s handling of byte ranges.

## Backfill existing managed audio

Use a principal authorized to process files in the workspace. The command works on one bounded page and reports a cursor for the next call.

```bash
php artisan media-studio:audio:waveforms WORKSPACE_ULID user USER_ID --limit=20
php artisan media-studio:audio:waveforms WORKSPACE_ULID user USER_ID --after=NEXT_CURSOR --limit=20
```

Replace `NEXT_CURSOR` with the numeric cursor printed by the first run. Stop when the cursor no longer advances. The backfill preserves the original audio. See [queue deployment](/queues-and-scheduler) and [CDN delivery](/cdn-delivery).
