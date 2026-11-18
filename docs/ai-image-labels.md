---
title: "AI labels for images and video"
---

AI labels are stored separately from manually assigned tags and Spatie Tags. Image analysis is disabled by default and never runs while browsing or searching.

Choose Moondream, Google Cloud Vision or Amazon Rekognition for image labels. Video analysis uses a limited set of sampled frames through the selected image provider. The AI page includes a paginated review panel for accepting or rejecting independent AI suggestions. OCR and lexical/hybrid/semantic retrieval are configured separately in [Search](/search). All processing is optional and disabled by default; browsing never starts a paid analysis. Set provider quotas and application admission limits before enabling automatic processing.

## Install optional tables

```sh
php artisan vendor:publish --tag=media-studio-intelligence-migrations
php artisan migrate
```

These tables are not part of default Studio migrations. They contain version-bound image analyses and independent AI labels; no user tags are created or modified.

## Choose one provider

```php
'intelligence' => [
    'enabled' => true,
    'images' => true,
    'videos' => false, // enable sampled-frame video analysis independently
    'provider' => 'google_vision', // moondream, rekognition, google_vision
    'max_labels' => 24, //1–64
    'analysis_preset' => 'ai-analysis-v1',
    'cache_store' => 'redis',
    'providers' => [
        'google_vision' => ['key' => env('FLEX_MEDIA_STUDIO_VISION_KEY')],
        'moondream' => [
            'key' => env('FLEX_MEDIA_STUDIO_MOONDREAM_KEY'),
            'model' => env('FLEX_MEDIA_STUDIO_MOONDREAM_MODEL'),
        ],
        'rekognition' => ['region' => env('AWS_DEFAULT_REGION')],
    ],
],
```

Set an explicit supported Moondream model; Studio does not silently change it. Rekognition requires the optional official `aws/aws-sdk-php` package and uses the standard AWS credential chain. The independent Studio package does not currently declare/install that optional SDK. Missing SDK/configuration fails closed. No provider credentials reach the browser or analysis tables.

## Prepare an analysis derivative

Use `intelligence.input` to select a ready current-version rendition, the managed
original or a registered external variant provider. The default names
`ai-analysis-v1` and permits a bounded temporary copy when that rendition is
missing; you do not have to create a permanent JPEG conversion. Configure
`missing => 'wait'` or `'skip'` when using the original as fallback is unwanted.
Selected inputs are normalized into a provider-compatible, metadata-stripped
temporary copy under byte/pixel limits, then discarded. Ordinary saved AVIF/WebP
conversions remain unchanged. See [input selection](/ai-upload-integration) for
all strategies and missing-input outcomes.

## Run explicitly

```sh
php artisan media-studio:analyze-image WORKSPACE_ID ENTRY_ID user USER_ID
```

The actor alias must be registered in Studio. The command checks workspace/tenant access and `studio.files.process` before reading, before the provider request and again before publication. A changed/trashed version cannot publish a stale result. External sources are not supported by this command yet.

A shared atomic cache lock prevents concurrent identical analysis; completed identical profile/version/derivative results are reused. Output is bounded to64short labels and a2000-character description. Moondream structured output is validated; its label scores remain unknown, not fabricated percentages. Google scores and AWS confidence values are retained on a0–1scale, but are not calibrated equivalently across providers. This version stores no raw provider response or per-object geometry.

API timeouts and no automatic HTTP retries reduce accidental repeated charges. An ambiguous network failure or process crash can still cause a repeated paid call on explicit retry; this is not an exactly-once billing guarantee. Use least-privilege credentials, supported provider regions and a shared lock cache. No live-provider quality/cost benchmark has been completed.


## Optional automatic upload analysis

Install all optional intelligence migrations, configure a provider, then enable:

```php
'automatic' => true,
'connection' => 'redis',
'queue' => 'media-intelligence',
'max_pending_per_workspace' => 100,
'max_source_bytes' => 10485760,
'max_source_pixels' => 16000000,
```

Run a worker on that queue with its retry-after greater than the job timeout of 570 seconds and lock lease of 600 seconds. Set the worker timeout consistently; configure finite storage/network timeouts.
Admission is attached to managed upload publication (including its shared chunk
completion path), after which dispatch occurs only after commit. The default
remains off. A synchronous queue never executes automatic analysis inside upload.
Oversized source files and a full workspace pending queue are not admitted.
Existing/externally imported assets are not automatically backfilled by this hook.

`fms_analysis_jobs` preserves queued/running/ready/failed/dispatch_failed/obsolete
state across reloads. Workers check current version before analysis, and the
analysis service checks permissions again. One attempt avoids automatic repeated
billable requests; job retry/recovery UI and durable dispatch reconciliation
are not implemented yet. A process killed without a queue failure callback may
leave a running record requiring operator intervention. This is not a complete
resilient outbox or a monetary cost budget. Do not enable unbounded external
retries. Duplicate automatic admission for the same version creates one record.

Analysis copies use existing GD/Imagick resource checks, no enlargement, metadata
stripping and private temporary streams cleaned after use. Original download is
bounded before decode (default10MiB, hard25MiB), source pixels default16MP/hard40MP.
Source checksum must match before encoding. No persistent JPEG delivery conversion
is added; provider compatibility conversion is temporary only.


## Select the image sent to AI

Use an existing conversion without changing the file shown to users:

```php
'intelligence' => [
    // Keep enabled/provider/credentials and other limits here.
    'input' => [
        'type' => 'rendition',
        'preset' => 'medium',
        'missing' => 'skip',
    ],
],
```

`type` accepts `auto`, `original`, `rendition` or `provider`. An external provider
uses the registered Studio variant-provider contract, with `provider` and `preset`
identifying the source. This supports a custom conversion system without depending
on a particular uploader. Missing conversions can use `temporary` processing of the
original, `wait` or `skip` (the latter two do not call AI). An existing derivative
must belong to the current version and pass access checks. Regardless of the
selected source, provider input is subject to the configured byte/pixel caps and
prepared in a compatible temporary format. Saved delivery variants are unchanged.

This command processes Studio-managed versions. Application Files and Spatie
projections require their own explicit processing adapter; registering a source
for viewing does not give Studio ownership of its originals.

## Sample video frames

See [video analysis and bounded frame sampling](/ai/video-analysis) for the
complete FFmpeg/FFprobe configuration, command and partial-result lifecycle.

## Review suggestions

Open **AI → AI labels** in a workspace after enabling image or video analysis.
The panel uses cursor pagination, a virtualized list and a bounded retained window.
Filter pending, accepted, rejected or all suggestions. Each item identifies the
file and provider; opening it uses the ordinary authorized file preview.

`studio.ai.view` permits inspection. `studio.ai.review` additionally permits
accept/reject actions; configure these using Studio's Spatie permission mapping.
Decisions use a revision check, so an outdated browser cannot silently overwrite
a newer decision. Replaced or trashed versions are excluded. Accepting a label
never writes a manual tag or a Spatie Tag.

Review applies to the selected analysis run of the current file version. A future analysis profile can create
new suggestions; cross-profile concept-level moderation is not currently applied.
Search indexes the latest current-version description and non-rejected labels.
Rejecting a label does not rewrite the provider's prose description. Synchronize
the search index after review; stale indexed fingerprints are rejected immediately.

Processing and indexing are separate: publishing an analysis does not mean the
search engine has already acknowledged its new document. No provider cost,
relevance or million-file throughput claim is implied by enabling this feature.
