---
title: "AI configuration reference"
description: "Every shipped intelligence, extraction, semantic-search and assistant setting with its default."
---

Paths are relative to `media-studio`. Defaults below assume environment variables
are unset. Provider credentials must remain server-side. This is the AI subset
of the [package-wide configuration reference](/configuration-reference); recipe,
model, embedder and provider maps are explained in their linked guides.

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

## Additional assistant staging override

`assistant.attachment_directory` is read at runtime and defaults to
`storage_path('app/private/media-studio-assistant')`, although it is not declared
in the shipped default array. It controls private temporary chat-attachment
staging. It does not change the managed library's disk/root.

Queue retries, source caps and worker deployment are explained in
[operations](/ai/operations). The `search.semantic.embedder` value is a Meilisearch
configuration array; see [provider configuration](/ai/providers).
