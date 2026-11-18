---
title: "Semantic and hybrid search"
description: "Configure meaning-based retrieval and the embeddings lifecycle."
---

Use this guide to enable optional meaning-based search in the Studio search modal. Keep [Meilisearch setup](/search) and [text extraction](/search-content-indexing) available when comparing results.

## Semantic and hybrid search

Studio uses Meilisearch's native embedding and vector retrieval. PHP does not scan
vectors or contact the image-label provider while searching. Configure the
embedding model separately from the image-analysis provider:

```php
'search' => [
    'enabled' => true,
    'semantic' => [
        'enabled' => true,
        'revision' => 'multilingual-v1',
        'embedder' => [
            'source' => 'huggingFace',
            'model' => 'intfloat/multilingual-e5-small',
            'documentTemplate' => '{{doc.name}} {{doc.ai}} {{doc.content}}',
            'documentTemplateMaxBytes' => 6000,
        ],
    ],
    // Keep your server-only Meilisearch connection here.
],
```

The example identifies a model, not a guarantee of your installation's supported
languages or relevance. Confirm the model/runtime support in your Meilisearch
installation. Studio accepts `huggingFace`, `openAi` and `rest` embedder settings;
credentials, REST templates and provider-specific parameters stay server-side.
The embedding input is capped at12000bytes, with a default6000-byte document
template budget. Use an explicit model/revision and validate your languages.
[Meilisearch embedding documentation](https://www.meilisearch.com/docs/capabilities/hybrid_search/getting_started).

Run `media-studio:search-index --configure`, wait for the settings task to succeed,
then index/synchronize. A model change can require engine-side re-embedding; do not
mix model spaces or assume a live atomic index switch. OCR keeps source-language
text. Visual labels/descriptions retain provider output. Multilingual matching is
provided by the configured embedding model, without translating each stored file
into every language.

```http
GET /media-studio/api/v1/workspaces/{workspace}/search?q=white%20cat&mode=lexical
GET /media-studio/api/v1/workspaces/{workspace}/search?q=white%20cat&mode=hybrid
GET /media-studio/api/v1/workspaces/{workspace}/search?q=white%20cat&mode=semantic
```

`lexical` is the default. `hybrid` uses equal lexical/semantic weighting;
`semantic` requests semantic retrieval only. Non-lexical modes are unavailable
while disabled. In Spotlight, **By meaning** searches semantically as you type.
**Smart** uses hybrid retrieval when it expands a search, if semantic search is
enabled. The same workspace and file filters apply to indexed results, which
open through normal access checks. Expiring cursors bind the query, mode,
workspace, actor, index and semantic configuration. Live ranking can change between
pages; this is a bounded result window, not a frozen or exhaustive result session.

The index receives file names, bounded content, and the latest current-version AI
description/non-rejected labels. Results recheck their content and AI projection
fingerprints in SQL. Changing/removing a projection therefore blocks stale hits
before the next engine synchronization. Search is not a permission database.
Real-engine relevance, provider quality/cost and production-scale throughput need
testing against your deployment and corpus; automated transport tests alone do
not establish those results.
