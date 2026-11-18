---
title: "Document thumbnails"
---

Document thumbnails are optional. They create a small image for library cards without replacing the uploaded document or creating a larger preview. PDF thumbnails use the first page only. Existing image and video conversions remain independently configurable.

## Enable PDF thumbnails without a queue worker

Install Poppler on the machine that will generate thumbnails and configure the executable path. Enable the feature in `config/media-studio.php`:

```php
'processing' => [
    // Keep your other processing settings.
    'document_thumbnails' => [
        'enabled' => true,
        'execution' => 'command',
        'pdf_binary' => '/usr/bin/pdftoppm',
        'edge' => 480,
        'format' => 'webp', // webp or avif; requires an available encoder
        'quality' => 78,
        'max_source_bytes' => 25 * 1024 * 1024,
        'max_raster_bytes' => 8 * 1024 * 1024,
        'timeout' => 30,
        'formats' => [
            'application/pdf' => [
                'enabled' => true,
                'generator' => \UseFlex\MediaStudio\DocumentThumbnails\PdfThumbnailGenerator::class,
            ],
        ],
    ],
],
```

Uploads record pending thumbnails and return without rendering the document. Run a bounded batch manually or through your scheduler:

```bash
php artisan media-studio:renditions:reconcile --documents --limit=25 --max-seconds=60
```

The time budget stops admission of additional work; an already running conversion has its own timeout and can finish after that budget. No thumbnail appears until a command or worker actually processes it. Library cards retain the file-type icon while waiting.

## Choose the PDF backend

Set `processing.document_thumbnails.pdf_backend` to `poppler` (default)
or `vips`. The environment variable `MEDIA_STUDIO_PDF_BACKEND` also selects
the backend when using the package configuration.

| Backend | Server requirements | Processing |
| --- | --- | --- |
| `poppler` | Poppler `pdftoppm` and the configured PHP image encoder | First-page PNG captured with bounded stdout, then encoded to WebP/AVIF |
| `vips` | `vips` with a PDF loader and WebP/AVIF saver | First-page thumbnail encoded directly by libvips |

For a small installation, use `poppler`, command execution and one scheduled
batch at a time. For a VPS, `vips` can run on the dedicated document queue.
Both backends require permission to start processes and a writable temporary
directory. Neither backend requires PHP FFI.

Example configuration for the libvips backend:

```php
'document_thumbnails' => [
    'enabled' => true,
    'pdf_backend' => 'vips',
    'vips_binary' => '/usr/bin/vips',
    'vips_concurrency' => 1,
    'execution' => 'queue',
    'connection' => 'redis',
    'queue' => 'document-thumbnails',
],
```

Install a libvips build with PDF and the desired output codec enabled. The PDF
loader can use PDFium or Poppler depending on that build. Check with
`vips -l pdfload` and render a trusted PDF before enabling it. Installing
ordinary libvips does not guarantee PDFium support.

Each conversion runs in a separate process with a deadline, bounded encoded output,
disabled libvips operation cache and 1–8 configured threads. The default is one
thread. Thread count limits libvips evaluation; decoder or encoder libraries may
have additional threads. Use OS/container memory and CPU limits for hard resource
isolation. Cache settings alone are not a process memory limit.

The libvips path writes the final encoded thumbnail to a temporary file and
streams it to the existing publication pipeline for validation. It avoids an
intermediate PNG and decoding/re-encoding that PNG in PHP. The source PDF is still
staged locally and validated in full. Existing pending recipes keep their stored
generator when the configured backend changes. Newly admitted recipes use the
selected backend. A missing executable or codec produces a retryable rendition
failure.

## Use a queue worker

```php
'document_thumbnails' => [
    'enabled' => true,
    'execution' => 'queue',
    'connection' => 'redis',
    'queue' => 'document-thumbnails',
    // Keep the formats and output settings from the example above.
],
```

```bash
php artisan queue:work redis --queue=document-thumbnails --timeout=120 --tries=3
```

Configure the connection retry interval above the worker timeout. A `sync` connection is deliberately not dispatched for documents: pending work remains available for the explicit command. Studio does not silently convert it inside the upload request.

## Explicit synchronous execution

For small trusted documents where extra upload latency is acceptable:

```php
'execution' => 'inline',
'formats' => [
    'application/pdf' => [
        'enabled' => true,
        'generator' => \UseFlex\MediaStudio\DocumentThumbnails\PdfThumbnailGenerator::class,
        'allow_inline' => true,
        'timeout' => 5,
        'max_source_bytes' => 2 * 1024 * 1024,
    ],
],
```

Synchronous execution adds conversion time to the upload response. It is not a background worker replacement for expensive documents.

## Disable generation

```php
'document_thumbnails' => ['enabled' => false],
```

No new document thumbnail jobs are admitted, and pending document processing stops. Previously created thumbnails and original files are retained. Disabling generation does not delete anything.

## Supply your own document generator

The PDF generator is included. Other formats can use a registered generator implementing this contract:

```php
use UseFlex\MediaStudio\DocumentThumbnails\ThumbnailGenerator;

final class CompanyDocumentThumbnail implements ThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        // Send the bounded local input to your isolated renderer.
        // Return a rewindable raster-image stream created with tmpfile().
        // The caller owns and closes that stream.
    }
}
```

Register it by MIME type:

```php
'formats' => [
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
        'enabled' => true,
        'generator' => CompanyDocumentThumbnail::class,
        'revision' => '1',
        'execution' => 'command',
        'max_source_bytes' => 20 * 1024 * 1024,
        'timeout' => 30,
    ],
],
```

The raster is encoded using Studio's WebP/AVIF pipeline and stored through the same Laravel disks as other renditions. Generators must enforce their own process/network/memory constraints. A PHP timeout alone is not an OS sandbox. Run document converters in an isolated, resource-limited worker environment. The included `OfficeThumbnailGenerator` uses LibreOffice and Poppler. Enable it only on an isolated conversion worker (see below). `TextThumbnailGenerator` produces a text-only thumbnail for TXT, Markdown and HTML; it does not execute HTML or preserve its visual page layout.

Profiles are recorded with the rendition. Changing configuration does not silently rewrite existing output. Use explicit backfill/refresh for existing assets.

## Existing documents

Use the registered principal type and ID of an authorized user:

```bash
# Inspect a bounded batch; no changes.
php artisan media-studio:renditions:backfill WORKSPACE PRINCIPAL_TYPE PRINCIPAL_ID --limit=100

# Admit missing thumbnails and existing configured image conversions.
php artisan media-studio:renditions:backfill WORKSPACE PRINCIPAL_TYPE PRINCIPAL_ID --limit=100 --apply

# Continue using the reported cursor.
php artisan media-studio:renditions:backfill WORKSPACE PRINCIPAL_TYPE PRINCIPAL_ID --after=LAST_ID --limit=100 --apply
```

The backfill command includes images as well as enabled document types. It does not scan your storage bucket. Source ownership, current versions and workspace permissions are checked again during processing. Existing source adapters and external renditions keep their own authority; the native processor does not rewrite externally owned files.

## Office documents

Run LibreOffice in an environment with no network access and OS-enforced CPU, memory and temporary-storage limits. The `office_isolated_worker` setting acknowledges that deployment requirement; it does not create a sandbox by itself. Never enable it on an unrestricted web server for untrusted uploads.

```php
'office_binary' => '/usr/bin/soffice',
'office_isolated_worker' => true,
'formats' => [
    'application/pdf' => [
        'enabled' => true,
        'generator' => \UseFlex\MediaStudio\DocumentThumbnails\PdfThumbnailGenerator::class,
    ],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
        'enabled' => true,
        'execution' => 'command',
        'generator' => \UseFlex\MediaStudio\DocumentThumbnails\OfficeThumbnailGenerator::class,
    ],
],
```

Register XLSX, PPTX, ODT, ODS and ODP using their MIME types and the same generator. The archive preflight rejects embedded active content, excessive expansion and external XML references. Each conversion has a private temporary directory and LibreOffice profile. A temporary PDF is removed after first-page rendering; only the thumbnail is published. Large spreadsheets may still be expensive to convert: configure print areas upstream and use a dedicated worker. The initial Office integration test covers DOCX, not every spreadsheet/presentation layout.

## Text, Markdown and text-only HTML

Configure a local Unicode TrueType font. No font or external resource is downloaded during rendering.

```php
'text_font' => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
'formats' => [
    'text/plain' => ['enabled' => true, 'generator' => \UseFlex\MediaStudio\DocumentThumbnails\TextThumbnailGenerator::class],
    'text/markdown' => ['enabled' => true, 'generator' => \UseFlex\MediaStudio\DocumentThumbnails\TextThumbnailGenerator::class],
    'text/html' => ['enabled' => true, 'generator' => \UseFlex\MediaStudio\DocumentThumbnails\TextThumbnailGenerator::class],
],
```

This renderer uses a bounded first text segment and stops when the card is filled. HTML scripts/styles are discarded; no browser is launched. For visually faithful HTML or Markdown rendering, provide your own isolated `ThumbnailGenerator`.

## Model-specific output

```php
'model_profiles' => [
    \App\Models\Post::class => ['edge' => 600, 'format' => 'avif', 'quality' => 55],
    \App\Models\PrivateDocument::class => ['enabled' => false],
],
```

Pass the owner model through the existing upload integration. Studio retains the rendition model on the source version and applies overrides when work is admitted. The master `enabled` switch always wins. To hide already-created document thumbnails too, set `serve_existing` to `false`.


## Format overrides and resource bounds

The master `enabled` switch must be true. Studio combines the MIME-type registration
with the matching owner `model_profiles` override, then uses global defaults for
unspecified settings. An enabled MIME registration alone does not start processing.

| Per-format/model option | Meaning |
| --- | --- |
| `enabled`, `generator` | Admission switch and `ThumbnailGenerator` class |
| `execution` | `command`, `queue` or explicitly permitted `inline` |
| `allow_inline` | Required opt-in for inline execution; omitted means false |
| `revision` | Generator recipe revision, default `1`; update deliberately when changing output behavior |
| `edge`, `format`, `quality` | Output bounds and WebP/AVIF encoding |
| `max_source_bytes` | Source admission cap; integer 1–268435456 bytes |
| `timeout` | Conversion deadline; integer 1–90 seconds |
| `pdf_backend` | PDF generator backend override: `poppler` or `vips` |
| `vips_concurrency` | libvips evaluation thread count, integer 1–8 |

`max_raster_bytes` is a **global** document setting, not a per-format override;
it accepts 1–33554432 bytes. Binary paths, text font and Office-worker acknowledgement
are also deployment configuration. Neither a byte budget nor the acknowledgement
switch replaces OS-level process resource isolation.

## Diagnose a missing thumbnail

| Observation | Check |
| --- | --- |
| Document opens but card has an icon | Browser preview and server thumbnail generation are independent. Check the document master switch and MIME registration. |
| Work stays queued in command mode | Run `media-studio:renditions:reconcile --documents`; no worker runs it automatically in this mode. |
| Work stays queued in queue mode | Check the document connection and queue name; a sync connection is deliberately not dispatched. |
| PDF conversion fails | Check executable permissions, actual PDF loader/output codec, source size and process timeout. |
| Office conversion fails | Check isolated worker acknowledgement, LibreOffice, Poppler, allowed MIME registration and archive preflight outcome. |
| Existing document is unchanged | Enable its generator and run explicit authorized backfill. Changing configuration does not rewrite an old recipe. |
| Thumbnail disappears after disabling | Generation and visibility are independent: check `serve_existing`, current source version and access. |

Use the [full processing reference](/configuration-processing) for all shipped
defaults, [video thumbnails](/video-thumbnails) for FFmpeg posters, and
[existing thumbnail mappings](/external-renditions) when another application owns
the derivative files.
