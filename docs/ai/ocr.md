---
title: "OCR for images and scanned PDFs"
description: "Extract source-language text without enabling visual auto-tagging or document thumbnails."
---

OCR is a text-extraction capability, not visual tagging. Native PDF text layers
can be extracted without a paid OCR request. For a textless image/page, the
shipped OCR adapter uses Google Vision. Install the optional search migrations
and configure the provider credential before enabling it.

## OCR for images and scanned PDF pages

OCR is independent of visual labels. You can leave `intelligence.enabled` off
while extracting text. Google Cloud Vision is the supported OCR provider; the
Moondream and Rekognition image-label adapters do not become document OCR engines.

```php
// Relevant sections of config/media-studio.php; retain your other settings.
'intelligence' => [
    'providers' => [
        'google_vision' => ['key' => env('FLEX_MEDIA_STUDIO_VISION_KEY')],
    ],
],
'search' => [
    'content' => [
        'enabled' => true,
        'max_source_bytes' => 4194304,
        'large_threshold_bytes' => 1048576,
        'oversize_policy' => 'skip',
        'max_characters' => 12000,
        'pdf' => [
            'enabled' => true,
            'text_binary' => '/usr/bin/pdftotext',
            'info_binary' => '/usr/bin/pdfinfo',
            'max_pages' => 25,
            'timeout_seconds' => 10,
        ],
        'ocr' => [
            'enabled' => true,
            'provider' => 'google_vision',
            'images' => true,
            'scanned_pdf' => true,
            'language_hints' => [],
            'max_pixels' => 16000000,
            'max_pdf_pages' => 5,
            'pdf_binary' => '/usr/bin/pdftoppm',
            'pdf_edge' => 2048,
            'page_timeout_seconds' => 15,
            'max_seconds' => 90,
        ],
    ],
    // Keep enabled, Meilisearch and cache settings here.
],
```

Use `media-studio:search-extract` as above. JPEG, PNG, WebP and AVIF are supported;
AVIF is temporarily decoded into a provider-compatible JPEG when needed. Image OCR
reads the managed original under the text-extraction source cap; it does not use
the separate visual-tagging rendition selector. It never uploads a publicly
accessible source URL. Source dimensions, bytes and UTF-8 provider output are
validated. Provider responses request text without per-character geometry, with a
256KiB response ceiling. Only the bounded text projection is retained.

PDF processing first extracts native text. Only textless pages are rasterized for
OCR, one page at a time. Pages with native text do not incur OCR requests. A blank
page can still produce an empty OCR result. The page cap, character budget and
elapsed-time admission budget can leave the document partially indexed. The last
admitted page may finish after the admission deadline, within its own decoder and
HTTP timeouts. No full-document coverage is claimed for limited runs.

Access/current-version checks run before each OCR page request and again before
publication. A completed current-version extraction with the same processing
profile is reused without a second OCR call. Changing queue routing does not
invalidate that result. Changing processing limits does. Private temporary files
are closed and removed after success or failure. Raw OCR payloads, page images and
all-language copies are not stored.


## Run one explicit extraction

```bash
php artisan media-studio:search-extract WORKSPACE_ID ENTRY_ID user USER_ID
```

The registered principal needs the source workspace's processing access. Use an
actual managed entry ID; this command does not recursively scan the library.
For new uploads, opt into [automatic content admission](/search-content-indexing#automatically-extract-newly-uploaded-content)
and run the `media-content` queue. Existing/imported files are not automatically
backfilled. Failed or ambiguous paid operations are not retried automatically.

## Make extracted text searchable

Enable and configure the optional Meilisearch integration, apply index settings
and synchronize the prepared projections. Extraction alone does not configure
an engine or its semantic embedder. See [content indexing](/search-content-indexing)
and [semantic retrieval](/semantic-search).

## Diagnose results

| Outcome | Meaning / next step |
| --- | --- |
| PDF has readable text already | Native text extraction is used for those pages; OCR is reserved for textless pages. |
| Partial extraction | Page, character or admission-time budget ended; it is not full-document coverage. |
| Empty OCR text | The image/page may contain no recognizable text; do not fabricate a successful transcript. |
| No automatic job | Check content.enabled, automatic, MIME/size admission, pending-job cap and async queue. |
| Upload shows only a file icon | OCR is not thumbnail generation; configure [document thumbnails](/document-thumbnails) separately. |
| Old text after replacing a file | Inspect version/profile state and synchronization; stale source fingerprints are rejected rather than trusted. |
