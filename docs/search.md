---
title: "Optional Meilisearch search"
---

Studio includes an optional server-side Meilisearch connection. It is disabled by default. The integration searches file names and optionally prepared text from managed TXT, Markdown, CSV and HTML files. Native PDF text extraction is optional. Optional Google Cloud Vision OCR supports images and scanned PDF pages. Separate AI label projections and optional Meilisearch-native semantic embeddings can also be indexed.

Open Spotlight from the library dock or press **S**. **Names & tags** is the default. With Meilisearch configured, select **Smart** to check the prepared index automatically when a query of at least three characters has no direct matches, or **Inside files** to search the index directly. Hashtag queries in **Smart** remain scoped to tags.

Open Search from the library action bar. Start with name and tag matches. Use the filters button to narrow the results by supported criteria such as file type, tags or uploader. Workspace selection is shown when multiple workspace choices are enabled. Indexed content and meaning-based modes depend on the selected workspace’s capabilities. [Filters and sorting](/filters-and-sorting)

## Configuration

Use a dedicated index and a server-only API key restricted to that index. Never expose the key to React or public configuration. Use HTTPS when connecting across machines; HTTP is intended for a trusted local connection.

```php
// config/media-studio.php
'search' => [
    'enabled' => true,
    'cache_store' => 'redis', // optional; must support atomic locks
    'meilisearch' => [
        'host' => env('FLEX_MEDIA_STUDIO_MEILISEARCH_HOST'),
        'key' => env('FLEX_MEDIA_STUDIO_MEILISEARCH_KEY'),
        'index' => 'studio_files',
    ],
],
```

Use different index names for different application installations. Changing the index name requires indexing that new index. Studio does not provision or start a Meilisearch server.

## Initial indexing

```sh
php artisan media-studio:search-index --configure --limit=120
php artisan media-studio:search-index --after=120 --limit=120
```

Use the actual `Next after` value printed by the preceding run, not an assumed batch number. Each call reads at most120 indexed database entries. It does not scan storage, fetch originals or analyze media. It reports a Meilisearch task identifier: accepted tasks are not necessarily completed tasks. Inspect task success in Meilisearch before relying on the index. A failed batch may be safely submitted again.

## Resumable synchronization

Install the optional synchronization tables only when you use this integration:

```sh
php artisan vendor:publish --tag=media-studio-search-migrations
php artisan migrate
```

Configure the index once using `media-studio:search-index --configure`. Then schedule:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:search-sync --pages=10 --limit=120 --max-seconds=20')
    ->everyMinute()
    ->withoutOverlapping();
```

Use a shared atomic-lock cache such as Redis or the Laravel database cache on multi-process installations. Every writer to the same index must share the same cache. The scheduler must actually be running; Studio does not install a system scheduler. The command works without a queue worker and does not wait in a polling loop.

Each invocation resumes a durable database checkpoint. It submits only changed names and separately removes documents for permanently deleted entries. Successful engine task completion is required before receipts advance. Pending tasks are checked on a later invocation; failures retain progress and are eligible for retry. Network errors never mark a batch completed.

Reconciliation traverses bounded SQL metadata pages, not source storage or full file contents. It periodically revisits known entries to catch changes, including direct query-builder writes. It does not resend unchanged documents. A full metadata cycle is still proportional to library size: this stage is scheduled reconciliation, not immediate transactional-outbox indexing. Configure frequency/batch budgets for your database; no million-file throughput is claimed.

Deletion is blocked immediately by SQL authorization; physical index cleanup follows the sweep. Do not independently restore or clear the index without resetting/rebuilding its receipts. Changing the configured index name starts a separate synchronization state. Atomic index rebuild switching and event-driven indexing remain future work.

## API

```http
GET /media-studio/api/v1/workspaces/{workspace}/search?q=hotel
```

The authenticated endpoint requires Studio view permission and current workspace access. Results contain at most60 matches. Continue with the opaque `nextCursor`; cursors expire after15minutes, bind the query/user/workspace/index and stop at1000 candidate results. Ranking can change while the index changes, so this initial pagination is not a stable snapshot.

Returned names come from the authoritative database, not untrusted search payloads. Source access, trash state, entry revision and current version are checked again for each page. Index counts and unverified snippets are not returned. A page may therefore contain fewer results or none while still having a next cursor. The index is not an authorization system.

When Meilisearch is unavailable, this endpoint returns503 with a generic message. Ordinary SQL browsing remains available; Studio never falls back to unrestricted results. Responses are private/no-store. Source access may change after a response, so file delivery independently checks access again.

## Verification scope

Contract tests additionally cover durable acknowledgement, failed tasks, updates during pending indexing, hard deletion and unchanged-cycle write suppression. Contract tests cover disabled-mode network isolation, cross-workspace filtering, revision/trash/access changes, generic failure handling and bounded index submissions. They do not establish live-server compatibility, Polish relevance, throughput or production-scale performance. Those require the configured Meilisearch server and a representative corpus.

## Optional text-file content

[Continue to Search inside files and scanned PDFs](/search-content-indexing#optional-text-file-content).

## PDF with an existing text layer

[Continue to Search inside files and scanned PDFs](/search-content-indexing#pdf-with-an-existing-text-layer).

### Markdown, TSV and oversized files

[Continue to Search inside files and scanned PDFs](/search-content-indexing#markdown-tsv-and-oversized-files).

### Large-file policy

[Continue to Search inside files and scanned PDFs](/search-content-indexing#large-file-policy).

## OCR for images and scanned PDF pages

[Continue to Search inside files and scanned PDFs](/search-content-indexing#ocr-for-images-and-scanned-pdf-pages).

## Automatically extract newly uploaded content

[Continue to Search inside files and scanned PDFs](/search-content-indexing#automatically-extract-newly-uploaded-content).

## Semantic and hybrid search

[Continue to Semantic and hybrid search](/semantic-search#semantic-and-hybrid-search).
