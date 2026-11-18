---
title: "Processing and AI configuration reference"
description: "Every shipped setting in this area, its default, purpose and related implementation guide."
---

Defaults below are from the package configuration with environment variables unset. Paths are relative to `media-studio`: for example, `storage.disk` means `config('media-studio.storage.disk')`. Empty arrays are extension points, not automatically discovered registrations.

See the [configuration guide](/configuration) for deployment steps and the [coverage and feature matrix](/configuration-reference) for scope.

## Application Files

Examples and operational requirements: [read the guide](/application-files).

| Setting | Default | Behavior |
| --- | --- | --- |
| `application_files.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `application_files.sources` | `[]` | Explicit model/source registrations; Studio never discovers all application models automatically. |
| `application_files.shield` | `false` | Enables the optional Shield permission integration for application-file sources. |
| `application_files.thumbnails.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `application_files.thumbnails.disk` | `"local"` | Laravel disk for generated application-file thumbnails, separate from source files. |
| `application_files.thumbnails.prefix` | `"flex-media-studio/application-thumbnails"` | Object-key prefix on the thumbnail disk. |
| `application_files.thumbnails.format` | `"avif"` | Output image codec; the installed encoder must support the selected format. |
| `application_files.thumbnails.edge` | `400` | Maximum thumbnail edge in pixels; preserves aspect ratio. |
| `application_files.thumbnails.quality` | `40` | Output image quality, from 1 to 100; file size depends on the encoder and source. |
| `application_files.thumbnails.avif_effort` | `3` | AVIF encoder effort; higher values trade processing time for compression. |
| `application_files.thumbnails.driver` | `"auto"` | Image renderer selection: auto, gd or imagick; requires installed codec support. |
| `application_files.thumbnails.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `application_files.thumbnails.queue` | `"media-application-thumbnails"` | Queue name consumed by the worker for this subsystem. |
| `application_files.thumbnails.max_source_bytes` | `12582912` | Maximum accepted source size in bytes for this processor. |
| `application_files.thumbnails.max_source_pixels` | `16000000` | Maximum decoded source pixel count (width × height). |
| `application_files.thumbnails.max_output_bytes` | `262144` | Maximum generated output size in bytes. |
| `application_files.thumbnails.max_cache_bytes` | `1073741824` | Thumbnail cache storage budget in bytes. |
| `application_files.thumbnails.max_cache_entries` | `25000` | Maximum cached thumbnail records. |
| `application_files.thumbnails.max_pending` | `128` | Maximum outstanding thumbnail jobs. |
| `application_files.thumbnails.ttl_minutes` | `1440` | Thumbnail cache lifetime in minutes. |
| `application_files.thumbnails.schedule_pruning` | `true` | Registers scheduled cache pruning when this feature is enabled. |
## Intelligence

Examples and operational requirements: [read the guide](/ai-image-labels).

| Setting | Default | Behavior |
| --- | --- | --- |
| `intelligence.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `intelligence.images` | `false` | Allows AI analysis of supported images; requires intelligence.enabled and a provider. |
| `intelligence.videos` | `false` | Allows bounded frame analysis of supported videos; requires intelligence.enabled and video tools. |
| `intelligence.provider` | `null` | Selected image-label provider, such as moondream, google_vision or rekognition. |
| `intelligence.automatic` | `false` | Admits work automatically after supported uploads; enabling the subsystem alone does not enable automatic analysis. |
| `intelligence.max_pending_per_workspace` | `100` | Maximum outstanding jobs admitted for one workspace. |
| `intelligence.queue` | `"media-intelligence"` | Queue name consumed by the worker for this subsystem. |
| `intelligence.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `intelligence.cache_store` | `null` | Laravel cache store used by this subsystem; null uses the configured application default. |
| `intelligence.max_source_bytes` | `10485760` | Maximum accepted source size in bytes for this processor. |
| `intelligence.max_source_pixels` | `16000000` | Maximum decoded source pixel count (width × height). |
| `intelligence.input.type` | `"auto"` | AI input selection strategy; auto selects according to the upload/input policy. |
| `intelligence.input.preset` | `"ai-analysis-v1"` | Named conversion preferred as AI input. |
| `intelligence.input.missing` | `"temporary"` | Behavior when the selected AI input rendition is missing; temporary allows a bounded temporary input. |
| `intelligence.max_labels` | `24` | Maximum number of labels accepted from one analysis. |
| `intelligence.analysis_preset` | `"ai-analysis-v1"` | Named image-analysis rendition; keep consistent with input policy and configured presets. |
| `intelligence.video.max_frames` | `4` | Maximum sampled frames sent for video analysis. |
| `intelligence.video.max_seconds` | `480` | Video analysis processing time budget in seconds. |
| `intelligence.video.max_source_bytes` | `134217728` | Maximum accepted source size in bytes for this processor. |
| `intelligence.video.max_duration_seconds` | `600` | Maximum video duration eligible for analysis, in seconds. |
| `intelligence.video.ffmpeg` | `null` | Path to the FFmpeg executable; configure FLEX_MEDIA_STUDIO_FFMPEG. |
| `intelligence.video.ffprobe` | `null` | Path to FFprobe for inspecting video inputs; configure FLEX_MEDIA_STUDIO_FFPROBE. |
| `intelligence.providers.moondream.key` | `null` | Moondream credential from FLEX_MEDIA_STUDIO_MOONDREAM_KEY. |
| `intelligence.providers.moondream.model` | `null` | Optional Moondream model override. |
| `intelligence.providers.google_vision.key` | `null` | Google Vision credential from FLEX_MEDIA_STUDIO_VISION_KEY. |
| `intelligence.providers.rekognition.region` | `null` | AWS Rekognition region; AWS_DEFAULT_REGION. Credentials come from the AWS integration. |
## Search

Examples and operational requirements: [read the guide](/search).

| Setting | Default | Behavior |
| --- | --- | --- |
| `search.enabled` | `false` | Enables optional search indexing; ordinary indexed database browsing remains independent. |
| `search.cache_store` | `null` | Laravel cache store used by this subsystem; null uses the configured application default. |
| `search.meilisearch.host` | `null` | Meilisearch endpoint; FLEX_MEDIA_STUDIO_MEILISEARCH_HOST. |
| `search.meilisearch.key` | `null` | Server-side Meilisearch credential; FLEX_MEDIA_STUDIO_MEILISEARCH_KEY. Never expose an admin key to the browser. |
| `search.meilisearch.index` | `"flex_media_studio"` | Meilisearch index name for Studio documents. |
| `search.semantic.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `search.semantic.revision` | `"1"` | Semantic indexing recipe revision; change deliberately when rebuilding embeddings. |
| `search.semantic.embedder` | `null` | Meilisearch embedder configuration array (openAi, huggingFace or rest); not a PHP callback/class. |
| `search.content.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `search.content.automatic` | `false` | Admits work automatically after supported uploads; enabling the subsystem alone does not enable automatic analysis. |
| `search.content.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `search.content.queue` | `"media-content"` | Queue name consumed by the worker for this subsystem. |
| `search.content.max_pending_per_workspace` | `100` | Maximum outstanding jobs admitted for one workspace. |
| `search.content.max_source_bytes` | `1048576` | Maximum accepted source size in bytes for this processor. |
| `search.content.max_characters` | `12000` | Maximum extracted characters retained per document. |
| `search.content.oversize_policy` | `"skip"` | skip rejects inputs above large_threshold_bytes; bounded permits them up to max_source_bytes. |
| `search.content.large_threshold_bytes` | `1048576` | Byte threshold used by the large-input extraction policy. |
| `search.content.pdf.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `search.content.pdf.text_binary` | `"pdftotext"` | Poppler pdftotext executable for PDF text extraction. |
| `search.content.pdf.info_binary` | `"pdfinfo"` | Poppler pdfinfo executable used for PDF inspection. |
| `search.content.pdf.max_pages` | `25` | Maximum PDF pages admitted for text extraction. |
| `search.content.pdf.timeout_seconds` | `10` | PDF extraction process timeout in seconds. |
| `search.content.ocr.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `search.content.ocr.images` | `false` | Allows OCR of image files when OCR is enabled. |
| `search.content.ocr.scanned_pdf` | `false` | Allows OCR of scanned PDF pages when OCR is enabled. |
| `search.content.ocr.provider` | `"google_vision"` | OCR provider; google_vision is the shipped default. |
| `search.content.ocr.language_hints` | `[]` | Language hints passed to the OCR provider. |
| `search.content.ocr.max_pixels` | `16000000` | Maximum source pixel count (width × height). |
| `search.content.ocr.max_pdf_pages` | `5` | Maximum PDF pages rasterized for OCR. |
| `search.content.ocr.pdf_binary` | `"pdftoppm"` | Poppler pdftoppm executable used for OCR rasterization. |
| `search.content.ocr.pdf_edge` | `2048` | Maximum rasterized PDF page edge in pixels for OCR. |
| `search.content.ocr.page_timeout_seconds` | `15` | Timeout per PDF page rasterization in seconds. |
| `search.content.ocr.max_seconds` | `90` | Total OCR processing time budget in seconds. |
## Video Editing

Examples and operational requirements: [read the guide](/video-editing).

| Setting | Default | Behavior |
| --- | --- | --- |
| `video_editing.min_selection_seconds` | `3` | Minimum selected trim duration in seconds. |
| `video_editing.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `video_editing.processor` | `"UseFlex\\MediaStudio\\Video\\FfmpegTrimProcessor"` | VideoTrimProcessor implementation; the shipped implementation uses FFmpeg. |
| `video_editing.mode` | `"queue"` | Trim execution mode; queue is the default, with explicit bounded synchronous execution available. |
| `video_editing.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `video_editing.queue` | `"video-edits"` | Queue name consumed by the worker for this subsystem. |
| `video_editing.ffmpeg` | `null` | Path to the FFmpeg executable; configure FLEX_MEDIA_STUDIO_FFMPEG. |
| `video_editing.ffprobe` | `null` | Path to FFprobe for inspecting video inputs; configure FLEX_MEDIA_STUDIO_FFPROBE. |
| `video_editing.cache_store` | `null` | Laravel cache store used by this subsystem; null uses the configured application default. |
| `video_editing.max_active` | `4` | Maximum queued/processing trim operations per workspace. |
| `video_editing.max_source_bytes` | `268435456` | Maximum accepted source size in bytes for this processor. |
| `video_editing.max_output_bytes` | `268435456` | Maximum generated output size in bytes. |
| `video_editing.max_duration` | `3600` | Maximum source video duration in seconds. |
| `video_editing.max_pixels` | `40000000` | Maximum source pixel count (width × height). |
| `video_editing.sync_max_bytes` | `26214400` | Maximum source bytes eligible for synchronous trimming. |
| `video_editing.sync_max_duration` | `120` | Maximum duration in seconds eligible for synchronous trimming. |
| `video_editing.timeout` | `60` | FFmpeg trim timeout in seconds. |
| `video_editing.crf` | `23` | Constant rate factor for video encoding; lower values favor quality and larger files. |
## Processing

Examples and operational requirements: [read the guide](/processing-configuration).

| Setting | Default | Behavior |
| --- | --- | --- |
| `processing.audio_waveforms.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `processing.audio_waveforms.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `processing.audio_waveforms.queue` | `"audio-waveforms"` | Queue name consumed by the worker for this subsystem. |
| `processing.audio_waveforms.ffmpeg` | `null` | Path to the FFmpeg executable; configure FLEX_MEDIA_STUDIO_FFMPEG. |
| `processing.audio_waveforms.max_source_bytes` | `134217728` | Maximum accepted source size in bytes for this processor. |
| `processing.audio_waveforms.max_seconds` | `7200` | Maximum audio duration admitted for waveform generation, in seconds. |
| `processing.document_thumbnails.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `processing.document_thumbnails.serve_existing` | `true` | Allows previously generated thumbnails to be served even when new generation is disabled. |
| `processing.document_thumbnails.execution` | `"command"` | command records pending work; queue uses an asynchronous worker; inline additionally requires per-format allow_inline. |
| `processing.document_thumbnails.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `processing.document_thumbnails.queue` | `"document-thumbnails"` | Queue name consumed by the worker for this subsystem. |
| `processing.document_thumbnails.edge` | `480` | Maximum thumbnail edge in pixels; preserves aspect ratio. |
| `processing.document_thumbnails.format` | `"webp"` | Output image codec; the installed encoder must support the selected format. |
| `processing.document_thumbnails.quality` | `78` | Output image quality, from 1 to 100; file size depends on the encoder and source. |
| `processing.document_thumbnails.max_source_bytes` | `26214400` | Maximum accepted source size in bytes for this processor. |
| `processing.document_thumbnails.max_raster_bytes` | `8388608` | Maximum intermediate/final raster stream size in bytes; does not replace OS memory isolation. |
| `processing.document_thumbnails.timeout` | `30` | Conversion process timeout in seconds; allowed 1–90. |
| `processing.document_thumbnails.pdf_backend` | `"poppler"` | PDF rasterizer: poppler or vips; MEDIA_STUDIO_PDF_BACKEND. |
| `processing.document_thumbnails.pdf_binary` | `"pdftoppm"` | Poppler pdftoppm path; renders the first PDF page. |
| `processing.document_thumbnails.vips_binary` | `"vips"` | libvips executable path; its build must contain PDF loader and output codec. |
| `processing.document_thumbnails.vips_concurrency` | `1` | libvips evaluation threads, integer 1–8; default 1. |
| `processing.document_thumbnails.text_font` | `null` | Absolute local TrueType font path for TXT/Markdown/text-only HTML thumbnails. |
| `processing.document_thumbnails.office_binary` | `"soffice"` | LibreOffice soffice executable for explicitly registered Office MIME types. |
| `processing.document_thumbnails.office_isolated_worker` | `false` | Acknowledges an externally isolated Office worker; this switch does not create process isolation. |
| `processing.document_thumbnails.model_profiles` | `[]` | Owner-model class mappings overriding document output/admission settings. |
| `processing.document_thumbnails.formats.application/pdf.enabled` | `true` | Enables this MIME type after the document master switch is enabled. |
| `processing.document_thumbnails.formats.application/pdf.generator` | `"UseFlex\\MediaStudio\\DocumentThumbnails\\PdfThumbnailGenerator"` | ThumbnailGenerator implementation for this MIME type; PDF backend selection can substitute the libvips implementation. |
| `processing.document_thumbnails.formats.text/plain.enabled` | `false` | Enables this MIME type after the document master switch is enabled. |
| `processing.document_thumbnails.formats.text/plain.generator` | `"UseFlex\\MediaStudio\\DocumentThumbnails\\TextThumbnailGenerator"` | ThumbnailGenerator implementation for this MIME type; PDF backend selection can substitute the libvips implementation. |
| `processing.document_thumbnails.formats.text/markdown.enabled` | `false` | Enables this MIME type after the document master switch is enabled. |
| `processing.document_thumbnails.formats.text/markdown.generator` | `"UseFlex\\MediaStudio\\DocumentThumbnails\\TextThumbnailGenerator"` | ThumbnailGenerator implementation for this MIME type; PDF backend selection can substitute the libvips implementation. |
| `processing.document_thumbnails.formats.text/html.enabled` | `false` | Enables this MIME type after the document master switch is enabled. |
| `processing.document_thumbnails.formats.text/html.generator` | `"UseFlex\\MediaStudio\\DocumentThumbnails\\TextThumbnailGenerator"` | ThumbnailGenerator implementation for this MIME type; PDF backend selection can substitute the libvips implementation. |
| `processing.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `processing.queue` | `"default"` | Queue name consumed by the worker for this subsystem. |
| `processing.thumbnails.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `processing.thumbnails.preset` | `"thumbnail-v1"` | Named rendition used for this role; must match a configured rendition or external mapping. |
| `processing.thumbnails.edge` | `400` | Maximum thumbnail edge in pixels; preserves aspect ratio. |
| `processing.thumbnails.max_pixels` | `16000000` | Maximum source pixel count (width × height). |
| `processing.thumbnails.max_source_bytes` | `26214400` | Maximum accepted source size in bytes for this processor. |
| `processing.preview.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `processing.preview.preset` | `"large-v1"` | Named rendition used for this role; must match a configured rendition or external mapping. |
| `processing.native.image_execution` | `"queue"` | queue dispatches native image work; command defers it to reconcile --images. |
| `processing.native.enabled` | `true` | Enables native image recipe admission; may also be an application callable/class implementing the existing source policy. |
| `processing.native.deduplicate` | `true` | Reuses compatible outputs within the native source-version batch; does not merge the whole library. |
| `processing.native.driver` | `"auto"` | Image renderer selection: auto, gd or imagick; requires installed codec support. |
| `processing.native.max_attempts` | `3` | Maximum processing attempts before work remains failed for explicit intervention. |
| `processing.native.max_working_bytes` | `268435456` | Working-memory budget used during native image processing, in bytes. |
| `processing.native.retirement_grace_seconds` | `86400` | Grace period in seconds before retired Studio-owned renditions are eligible for pruning. |
| `processing.native.strip_metadata` | `true` | Removes source metadata from generated output; does not rewrite the stored original. |
| `processing.originals.keep` | `true` | Keeps uploaded originals. False opts into the documented original-replacement lifecycle. |
| `processing.originals.replacement_preset` | `"large-v1"` | Ready rendition eligible to replace an original under that lifecycle. |
| `processing.originals.grace_seconds` | `86400` | Grace interval in seconds for original replacement/cleanup. |
| `processing.renditions.thumbnail-v1.width` | `400` | Maximum output width in pixels for this named rendition. |
| `processing.renditions.thumbnail-v1.height` | `400` | Maximum output height in pixels for this named rendition. |
| `processing.renditions.thumbnail-v1.format` | `"avif"` | Output image codec; the installed encoder must support the selected format. |
| `processing.renditions.thumbnail-v1.quality` | `40` | Output image quality, from 1 to 100; file size depends on the encoder and source. |
| `processing.renditions.thumbnail-v1.avif_effort` | `3` | AVIF encoder effort; higher values trade processing time for compression. |
| `processing.renditions.medium-v1.width` | `1000` | Maximum output width in pixels for this named rendition. |
| `processing.renditions.medium-v1.height` | `1000` | Maximum output height in pixels for this named rendition. |
| `processing.renditions.medium-v1.format` | `"avif"` | Output image codec; the installed encoder must support the selected format. |
| `processing.renditions.medium-v1.quality` | `40` | Output image quality, from 1 to 100; file size depends on the encoder and source. |
| `processing.renditions.medium-v1.avif_effort` | `3` | AVIF encoder effort; higher values trade processing time for compression. |
| `processing.renditions.large-v1.width` | `1900` | Maximum output width in pixels for this named rendition. |
| `processing.renditions.large-v1.height` | `1900` | Maximum output height in pixels for this named rendition. |
| `processing.renditions.large-v1.format` | `"avif"` | Output image codec; the installed encoder must support the selected format. |
| `processing.renditions.large-v1.quality` | `50` | Output image quality, from 1 to 100; file size depends on the encoder and source. |
| `processing.renditions.large-v1.avif_effort` | `3` | AVIF encoder effort; higher values trade processing time for compression. |
| `processing.model_renditions` | `[]` | Owner-model class mappings to rendition definitions; separate from document model profiles. |
| `processing.video.enabled` | `false` | Enables video recipe admission; requires an asynchronous processing connection. |
| `processing.video.compress` | `false` | Creates an MP4 playback rendition; independent of poster generation. |
| `processing.video.poster` | `true` | Creates a still-image thumbnail from the video. |
| `processing.video.ffmpeg` | `null` | Path to the FFmpeg executable; configure FLEX_MEDIA_STUDIO_FFMPEG. |
| `processing.video.poster_edge` | `480` | Maximum poster edge, clamped to 64–1200 pixels. |
| `processing.video.poster_format` | `"webp"` | Poster codec: webp or avif; requires the corresponding FFmpeg encoder. |
| `processing.video.poster_quality` | `78` | Poster image quality, clamped to 1–100. |
| `processing.video.strip_metadata` | `true` | Removes source metadata from generated output; does not rewrite the stored original. |
| `processing.video.max_edge` | `1280` | Playback rendition edge, clamped to 320–1920 pixels. |
| `processing.video.crf` | `26` | Constant rate factor for video encoding; lower values favor quality and larger files. |
| `processing.video.max_source_bytes` | `268435456` | Maximum accepted source size in bytes for this processor. |
| `processing.video.max_output_bytes` | `268435456` | Maximum generated output size in bytes. |
| `processing.external_providers` | `[]` | Application registrations for externally owned rendition providers. |
## Assistant

Examples and operational requirements: [read the guide](/assistant).

| Setting | Default | Behavior |
| --- | --- | --- |
| `assistant.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `assistant.connection` | `null` | Asynchronous Laravel queue connection for conversational runs; sync is not supported. |
| `assistant.queue` | `"media-studio-ai"` | Queue name consumed by the worker for this subsystem. |
| `assistant.max_active_runs` | `2` | Maximum active assistant runs for the admission slot; clamped to 1–8. |
| `assistant.retention.enabled` | `false` | Enables automatic assistant-history retention cleanup. |
| `assistant.retention.inactive_days` | `90` | Inactive conversation retention in days. |
| `assistant.retention.deleted_days` | `30` | Retention in days for deleted conversations. |
| `assistant.retention.batch` | `50` | Maximum conversations handled in one retention batch. |
| `assistant.models` | `[]` | Named Laravel AI provider/model choices with label and optional usage_weight; configure provider credentials in ai.php. |
