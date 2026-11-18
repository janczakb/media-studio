---
title: "Analyze video frames with AI"
description: "Configure bounded FFmpeg frame sampling, inspect timecodes and understand partial results."
---

## Sample video frames

```php
'intelligence' => [
    'enabled' => true,
    'images' => false,
    'videos' => true,
    'provider' => 'google_vision',
    'video' => [
        'ffmpeg' => '/usr/bin/ffmpeg',
        'ffprobe' => '/usr/bin/ffprobe',
        'max_source_bytes' => 134217728, //128MiB
        'max_duration_seconds' => 600,
        'max_frames' => 4,
        'max_seconds' => 480,
    ],
    // Keep provider credentials and queue configuration here.
],
```

```sh
php artisan media-studio:analyze-image WORKSPACE_ID ENTRY_ID user USER_ID --video
```

Configure actual executable paths for your server. Supported sources include MP4,
MOV, WebM and Matroska. Studio verifies the source checksum, limits temporary
storage, probes duration/dimensions, then samples evenly spaced JPEG frames up to
768px. Byte-identical frames within a run reuse the same provider result. At most
12frames are allowed; the defaults analyze4. A time-budget check before each sample
reserves time for its bounded decoder/provider request. This is not a guarantee
that every event in the video is detected.

Published results have the explicit `ready_partial` state and bounded timecode
records. Originals and ordinary video conversions remain unchanged. Access and
current-version checks run before every sample submission and again before
publication. Completed identical runs are reused. A failed run may have incurred
provider charges; an explicit rerun can repeat them. Native asynchronous AWS video
analysis and a monetary accounting ledger are not included.


## Posters and editing are independent

AI samples are temporary analysis inputs. They do not enable [video posters](/video-thumbnails), recompress playback or enable [video trimming](/video-editing). Automatic admission uses the same intelligence queue described in [visual analysis](/ai-image-labels#optional-automatic-upload-analysis).
