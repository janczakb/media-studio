---
title: "Trim videos"
---

Open a managed video and choose **Trim video** in the preview action bar. Drag the start and end handles or enter the times in seconds. The play button previews the selected section. **Cancel** discards the selection. **Save trimmed video** makes the result the current version and retains the previous file in version history.

## Enable trimming

Set these options under `video_editing` in the Media Studio configuration:

```php
'video_editing' => [
    'enabled' => true,
    'min_selection_seconds' => 3,
    'ffmpeg' => '/usr/bin/ffmpeg',
    'ffprobe' => '/usr/bin/ffprobe',
    'mode' => 'queue',
    'queue' => 'video-edits',
],
```

Run the package migrations and start a worker on the configured queue. Set the queue connection's retry interval above the job timeout. The built-in processor produces H.264/AAC MP4 and requires both executables on the machines handling edits. Use `mode => 'sync'` only for small clips where a worker is unavailable. Studio applies the stricter synchronous size and duration limits in that mode. Set `processor` to an implementation of `VideoTrimProcessor` to use a different backend.

Users need permission to process and update the file. The action appears for eligible videos stored and versioned by Studio.

## Filmstrip

Studio loads the filmstrip when the editor opens. If the file has a playback conversion, the browser samples frames from that smaller video. Otherwise it samples the original. A filmstrip stays in memory while the same file preview remains open. Thumbnail availability depends on the browser's decoder and the source's media and CORS settings.

Set `videoEditing: {thumbnailCount: 6}` in the React options to use fewer frames. Set `videoEditing: {timelineThumbnails: false}` to disable frame sampling.

The editor uses Video.js for playback. Its standard controls are hidden in trim mode by default. Set `videoEditing: {showPlayerControls: true}` to show them alongside the trim controls.
