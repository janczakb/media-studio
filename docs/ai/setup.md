---
title: "AI setup and capability boundaries"
description: "Install only the required migrations and distinguish analysis, text extraction, embeddings and chat."
---

Begin with a functioning Studio library, a registered principal and the correct
tenant context. AI never replaces workspace authorization. Keep every optional
switch off until its dependencies and provider settings are ready.

## Install the tables you need

| Capability | Optional migration publish tag | Other dependency |
| --- | --- | --- |
| Visual labels / sampled video | `media-studio-intelligence-migrations` | Configured Moondream, Google Vision or Rekognition adapter |
| Content extraction, OCR and indexed search | `media-studio-search-migrations` | Optional Poppler, OCR credentials and/or Meilisearch depending on the feature |
| Conversational assistant | `media-studio-assistant-migrations` | Compatible `laravel/ai`, configured provider/models and asynchronous queue |

For example, enable visual analysis and content extraction:

```bash
php artisan vendor:publish --tag=media-studio-intelligence-migrations
php artisan vendor:publish --tag=media-studio-search-migrations
php artisan migrate
```

For chat, follow the separate [assistant installation](/assistant#enable-chat).
Apply all migrations in each selected optional group when upgrading, rather than
copying only its first create-table migration. Core Studio migrations are still
required.

## Four independent configuration areas

```php
// Capability switches only; merge with the complete provider/worker examples.
'intelligence' => ['enabled' => false, 'images' => false, 'videos' => false],
'search' => [
    'enabled' => false,
    'semantic' => ['enabled' => false],
    'content' => ['enabled' => false, 'ocr' => ['enabled' => false]],
],
'assistant' => ['enabled' => false],
```

`intelligence` owns visual labels; `search.content` owns text/OCR projections;
`search.semantic` configures Meilisearch embeddings; `assistant` owns chat.
OCR uses the configured Google Vision credential but does not require enabling
automatic visual tagging. A provider configured in Laravel `ai.php` does not
configure `intelligence.providers`, and vice versa.

## First-file rollout

Choose one non-sensitive managed asset in a test workspace. Register the CLI
principal alias, grant only the required Studio ability and run the documented
single-file command. Inspect its persisted result/state before testing automatic
upload admission. Verify a denied user and a different workspace cannot access
that result. Content on imported/external sources needs its explicit supported
adapter; mounting it for browsing does not transfer ownership to an AI processor.

Use [operations](/ai/operations) for queue timeouts, failure handling and quotas.
Use [the provider guide](/ai/providers) to choose the correct credential namespace.
