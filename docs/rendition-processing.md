---
title: "Optimize and recover media processing"
description: "Run or recover conversions, generate video posters and share identical outputs."
---

Use these workflows to recover a failed conversion or process a library incrementally. Check [queue deployment](/queues-and-scheduler) before enabling background processing.

## Optimize images already in Studio

Open the file's **More actions** menu and choose **Optimize image**. The worker
uses the current presets to generate variants. The previous conversions remain
available during processing and after a failed replacement. Open **File
information** to compare each resulting file size with the original.

The default keeps previously generated files. Choose **Remove replaced
conversions after the retention period** to make old Studio-generated variants
eligible for cleanup. The original file is preserved in both cases.

For a large library, process images in bounded batches. The default command
reports the selected batch. Add `--apply` to queue it. Use the printed cursor
with `--after` to resume. The principal must have write access to the workspace.

```bash
php artisan media-studio:renditions:backfill WORKSPACE_ULID user USER_ID --limit=250
php artisan media-studio:renditions:backfill WORKSPACE_ULID user USER_ID --limit=250 --apply
php artisan media-studio:renditions:backfill WORKSPACE_ULID user USER_ID --limit=250 --refresh --retention=prune --apply
php artisan media-studio:renditions:prune WORKSPACE_ULID --limit=250
php artisan media-studio:renditions:prune WORKSPACE_ULID --limit=250 --apply
```

Backfill without `--refresh` generates missing presets. Use `--refresh` to
rebuild existing Studio-owned variants, with `--retention=keep` by default.
Cleanup is opt-in and applies after `processing.native.retirement_grace_seconds`
(default 24 hours). Schedule the prune command if you want periodic cleanup.

For media managed by Spatie or an external provider, run that application's own
conversion job and resync the source in Studio. The source retains ownership of
its files and its retention policy.

## Generate video posters and playback previews

Video processing is optional and uses a background queue. Set
`FLEX_MEDIA_STUDIO_FFMPEG` to the absolute path of the FFmpeg executable.
On macOS, Homebrew's `ffmpeg-full` includes both poster encoders.

```php
// Inside the processing configuration
'video' => [
    'enabled' => true,
    'poster' => true,
    'poster_format' => 'webp', // webp or avif
    'poster_quality' => 78,
    'poster_edge' => 480,
    'compress' => true,
    'max_edge' => 1280,
    'crf' => 26,
],
```

The WebP poster uses `libwebp` and the AVIF poster uses `libaom-av1`. The
optional MP4 playback preview uses `libx264`. Check the executable with
`ffmpeg -encoders`. Downloads retain the original. Files awaiting an MP4
preview show **Processing video** in the library.

## Recover interrupted processing

Run the scheduler and register the bounded reconciliation command.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:renditions:reconcile --limit=100')
    ->everyMinute()
    ->withoutOverlapping();
```

Studio retries undispatched jobs, dispatch failures and expired processing
leases, subject to retry limits. Workers recheck workspace permissions and the
source version before publishing. A file with changed bytes is rejected. An
unavailable variant keeps the corresponding placeholder visible until a valid
output is ready.

## Share identical small-image outputs

Native image batches process the largest requested bounds first. When smaller
profiles resolve to the same geometry and compatible settings, their named
renditions reference the already-published object rather than encoding and
storing another copy. With the default profile, an 80×40 image has one physical
AVIF at large quality 50 while thumbnail, medium and large remain available.

Sharing is scoped to the source version and processing batch. Different formats,
crop geometry, metadata settings or destination disks remain separate. A shared
object is protected while any rendition or asset version still references it.

Disable it globally:

```php
'processing' => [
    'native' => ['deduplicate' => false],
],
```

Or disable sharing for an individual conversion:

```php
'medium-v1' => [
    'width' => 1000, 'height' => 1000,
    'format' => 'avif', 'quality' => 40,
    'deduplicate' => false,
],
```

This does not scan and merge previously generated files automatically.


### Verified AVIF output

AVIF requires encoder support in the installed GD or ImageMagick build. Automatic driver selection uses an available encoder; selecting an unsupported driver reports a conversion failure instead of saving another format with an `.avif` extension. Before publication, Studio checks the encoded file's actual MIME type, dimensions and AVIF container brand.

Quality and effort settings control compression, but different encoders may produce different file sizes from Sharp. AVIF is not guaranteed to be smaller than WebP for every image.

## Generate images without a permanent queue worker

Use command mode if your hosting can run Laravel scheduled commands but does not maintain a queue worker:

```php
// config/media-studio.php — inside processing.native
'image_execution' => 'command',
```

Uploads and explicit backfill record pending image conversions without generating them in the upload request. Run a bounded batch manually:

```bash
php artisan media-studio:renditions:reconcile --images --limit=100 --max-seconds=60
```

Or schedule it in your application:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:renditions:reconcile --images --limit=100 --max-seconds=60')
    ->everyMinute()
    ->withoutOverlapping();
```

Your server must run Laravel's scheduler. Pending statuses remain available after reload, and Studio's existing processing UI reflects completed variants. This also supports explicitly enabled image conversions from imported disk sources. Document thumbnail execution and video processing retain their own settings. Use `--documents` separately for document thumbnails; it cannot be combined with `--images`.

`--limit` bounds candidate rendition rows, not the number of output files: one admitted image batch can generate its configured variants together. The time budget stops admitting new batches; it does not interrupt an encoder already running. Keep source size, pixel and memory limits appropriate for your server. A failed image is recorded and does not prevent later admitted image batches from being attempted; the command returns a failure exit code if any batch fails. Existing attempt limits still apply.

The default `image_execution` is `queue`, preserving the configured queue connection's behavior. Processing still runs on your PHP server and uses temporary local space even when originals and all generated files are stored on S3.
