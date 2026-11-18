---
title: "Search inside files and scanned PDFs"
description: "Configure text extraction, PDF text layers, OCR, limits and extraction jobs."
---

[Meilisearch](/search) provides the search index. This guide covers how supported file contents reach that index. Start with a small test set containing a text file, an indexed PDF and a scanned PDF.

## Optional text-file content

Enable text extraction separately after installing the optional search migrations:

```php
'search' => [
    'enabled' => true,
    'content' => [
        'enabled' => true,
        'max_source_bytes' => 1048576,
        'max_characters' => 12000,
    ],
    // Keep your Meilisearch and cache configuration here.
],
```

Extraction is explicitly invoked for one managed file using a registered principal alias and its actual identifier:

```sh
php artisan media-studio:search-extract WORKSPACE_ID ENTRY_ID user USER_ID
```

The command checks workspace access and the actor's `studio.files.process` permission before reading and again before saving. Custom permission mappings remain supported. It does not grant access by accepting an arbitrary PHP model class. A configured tenant resolver must resolve the intended tenant context for CLI use.

The source must have valid UTF-8 encoding and match its registered content checksum. Supported MIME types are `text/plain`, `text/markdown`, `text/csv` and `text/html`. HTML scripts/styles/embedded active content are removed without execution or remote fetching. Plain text and Markdown are not interpreted as HTML. External source processing is not supported by this extractor yet.

Source bytes default to1MiB with a hard maximum4MiB. Extracted text defaults to12000 Unicode characters with the same hard maximum, keeping each stored projection below the SQL TEXT byte limit. Longer text gets the explicit `partial` state; this is not full-document coverage. Unsupported/oversized files do not become successful extracted documents. Invalid encodings and changed bytes fail without publication. Original files remain unchanged.

Run `media-studio:search-index --configure` again to add `content` to searchable fields, then run scheduled synchronization. Content uses a version-bound fingerprint independent of the file name. Updating the content projection therefore causes reindexing even if the display name did not change. Search never fetches originals or parses files. It loads only projection hashes to verify current hits, not the stored text body. Text and raw engine payloads are not returned in result cards.

Disabling content immediately rejects hits carrying old content fingerprints; name results may temporarily disappear until synchronization removes content from those indexed documents. Stored projections are retained when disabled, not automatically destroyed. Deleting an entry cascades its SQL text projection; its engine document is removed by synchronization. Optional upload-triggered extraction is described below. Existing/imported files require explicit extraction; there is no automatic full-library content scan.

## PDF with an existing text layer

Native PDF extraction uses optional Poppler command-line tools already installed on your server. Without OCR enabled, it requires no AI API or page rendering. Enable it separately:

```php
'content' => [
    'enabled' => true,
    'max_source_bytes' => 4194304, // hard maximum4MiB in this release
    'max_characters' => 12000,
    'pdf' => [
        'enabled' => true,
        'text_binary' => '/usr/bin/pdftotext',
        'info_binary' => '/usr/bin/pdfinfo',
        'max_pages' => 25,
        'timeout_seconds' => 10,
    ],
],
```

Use the same `media-studio:search-extract` command as for text files. Paths depend on the server installation. Missing executables, encrypted documents, invalid PDFs and parser failures do not become successfully indexed content. Both PDF and ordinary content extraction remain off by default.

Each parser process has a timeout (maximum30seconds), page selection (maximum100pages), bounded captured output and no shell interpolation. Metadata output is limited to64KiB and extracted text output to256KiB; exceeding these bounds fails rather than silently publishing incomplete parser output. Final indexed text remains capped at12000Unicode characters. Source files use private temporary staging cleaned in a finally block. For untrusted documents, run processing workers with OS/container memory, CPU and filesystem restrictions; a subprocess timeout is not an OS memory sandbox.

A processed document receives:

- `ready`: text was extracted within configured coverage limits;
- `partial`: page/character limits were reached, or selected pages include empty text;
- `no_text`: the selected pages yielded no text. This may be a scan, a blank document or unsupported text encoding; it is not an OCR result.

Empty pages alone do not prove that OCR is needed. For mixed scanned/text PDFs, native text is preferred; optional page-selective OCR handles textless pages within a separate budget. Skipped pages keep coverage partial. It does not retain page images or per-character geometry. PDF page snippets/highlights are not exposed by this initial text index.

### Markdown, TSV and oversized files

Markdown files (`.md`) are supported when identified as `text/plain`,
`text/markdown` or `text/x-markdown`; optional charset parameters are normalized.
TSV (`text/tab-separated-values`) and `application/csv` are also supported.
A binary file renamed `.md` is not accepted as text. RTF and Office formats need
separate parsers and are not plain-text formats in this extractor.

Oversized sources persist `skipped_size` before opening the storage stream. This is
distinct from `unsupported`. The current implementation does not index10000-page
documents fully or resume from a page/byte checkpoint. Character-limited output
is explicitly partial. Full large-document background chunking remains planned;
searching never scans original document content on request.


### Large-file policy

```php
'oversize_policy' => 'skip', // default: skip or bounded
'large_threshold_bytes' => 1048576,
'max_source_bytes' => 4194304,
'max_characters' => 12000,
```

`skip` excludes files larger than the soft threshold before downloading any
original bytes. `bounded` permits extraction above the soft threshold, but never
above `max_source_bytes` (hard maximum4MiB). It retains existing page/character
limits and explicit partial coverage; it is not unlimited or resumable processing.
The soft threshold must be positive and no larger than the hard cap.

Skipped files persist `skipped_size` with an empty projection for their current
version. An earlier extracted body is cleared and index synchronization removes
its searchable text. Names can remain searchable. The state is an extraction
status, not an upload rejection: the original is retained unchanged. After
changing limits, explicitly rerun extraction for skipped files. No automatic
retry loop repeatedly downloads oversized originals.

## OCR for images and scanned PDF pages

Use [image and scanned-PDF OCR](/ai/ocr) for the complete provider, per-page
resource limits and source-language extraction behavior. Native PDF text
extraction above remains independent of the paid OCR path.

## Automatically extract newly uploaded content

After publishing **all** optional search migrations, enable separately:

```php
'search' => [
    'content' => [
        'enabled' => true,
        'automatic' => true,
        'connection' => 'redis',
        'queue' => 'media-content',
        'max_pending_per_workspace' => 100,
        // Include the desired extraction/OCR limits above.
    ],
],
```

Admission follows managed upload publication and dispatches after commit. A sync
queue is deliberately ignored: OCR is never performed inside an upload response.
Run a worker with a timeout consistent with the 390-second job timeout and set
retry-after above the 420-second lock lease. Configure finite object-storage
network timeouts as well. Oversized sources and a full pending queue are not
admitted. Jobs have one attempt; failed or ambiguous paid operations are not
retried automatically. A killed worker may require operator recovery. This hook
is not a durable dispatch outbox or a bulk backfill service.
