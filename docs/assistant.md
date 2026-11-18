---
title: Configure the Studio assistant
description: Enable chat, configure models and workers, use file context, and understand history retention.
---

Studio's assistant is optional and uses the models configured on the Laravel host. It can answer questions about attached files and use Studio actions according to the account's workspace permissions. An external AI client uses the separate [MCP integration](/mcp).

## Enable chat

Install the Laravel AI integration compatible with your Laravel version, configure your provider on the server, then apply the assistant migrations.

```bash
composer require laravel/ai
php artisan vendor:publish --tag=media-studio-assistant-migrations
php artisan migrate
```

```php
// config/media-studio.php
'assistant' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'media-studio-ai',
    'max_active_runs' => 2,
    'models' => [
        'fast' => [
            'label' => 'Fast',
            'provider' => 'openai',
            'model' => env('STUDIO_ASSISTANT_MODEL'),
            'usage_weight' => 1,
        ],
    ],
],
```

Set `STUDIO_ASSISTANT_MODEL` to a model available to your provider account. Configure its server-side credentials and the Redis queue connection in Laravel.

```bash
php artisan queue:work redis --queue=media-studio-ai
```

## Work with files

Open **AI chats** to resume a saved conversation or create a new one. When a preview is open, add **Current file** to give the assistant the specific file. You can also attach a file from the compose box and choose a configured model. While a response runs, **Stop** requests cancellation.

Try a prompt such as “Summarize this PDF and list the actions on page two” with the document attached, or “Find the latest version of the launch brief and open its preview” inside the right workspace. When an action cannot unambiguously identify a destination, specify the folder or file name. Previewing and mutating files both depend on the same workspace permissions.

## History and usage

Conversations are private to their owner within a workspace. The interface pages through saved chat history independently from the model's rolling context. An optional retention policy removes inactive or deleted chats according to server configuration. Configure [character appearance](/characters) separately and refer to [assistant history](/assistant-history) for cursor examples and context limits.

If chat is visible but a request stays queued, check the selected worker connection and queue. If the provider rejects a request, check the configured model, credential and input support for the file type.

For per-installation history cleanup, see [Assistant retention](/assistant-retention). To let users search files by meaning in the search modal, configure [semantic and hybrid search](/semantic-search).

## Context, attachments and actions

See [assistant context and tools](/ai/assistant-context) for exact input formats, size limits, current-file behavior, available actions and provider context bounds. Configure [models](/ai/providers) independently of visual tagging and OCR, and deploy the correct [worker](/ai/operations).
