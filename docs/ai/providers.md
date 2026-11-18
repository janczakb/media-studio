---
title: "AI providers and model configuration"
description: "Configure visual-analysis adapters, Google Vision OCR, Meilisearch embedders and Laravel AI chat separately."
---

Studio does not use one provider configuration for every AI operation. Keep
credentials on the server and configure only the capabilities you intend to use.

## Visual labels and frame analysis

| Adapter | Configuration | Requirements |
| --- | --- | --- |
| `google_vision` | `intelligence.providers.google_vision.key` | Google Vision credential for the shipped adapter |
| `moondream` | `intelligence.providers.moondream.key`, `.model` | Credential and explicit supported model |
| `rekognition` | `intelligence.providers.rekognition.region` | Optional official `aws/aws-sdk-php` and AWS credential chain |

```php
'intelligence' => [
    'enabled' => true,
    'images' => true,
    'provider' => 'google_vision',
    'providers' => [
        'google_vision' => ['key' => env('FLEX_MEDIA_STUDIO_VISION_KEY')],
    ],
],
```

To select Moondream instead, keep the other admission limits and change the
selected adapter:

```php
'provider' => 'moondream',
'providers' => [
    'moondream' => [
        'key' => env('FLEX_MEDIA_STUDIO_MOONDREAM_KEY'),
        'model' => env('FLEX_MEDIA_STUDIO_MOONDREAM_MODEL'),
    ],
],
```

For Rekognition, configure `provider => 'rekognition'` and the AWS region, install
the optional SDK, then give the worker least-privilege AWS credentials through
the host's AWS integration. Missing SDK/configuration fails the operation; Studio
does not substitute another provider. The image adapters differ in description,
label and confidence output; scores are not calibrated equivalently. See
[analysis behavior](/ai-image-labels) before comparing results.

## OCR provider

The shipped OCR path supports `search.content.ocr.provider => 'google_vision'`
and uses `intelligence.providers.google_vision.key`. It has its own image/PDF
switches and processing limits. Choosing a visual-label provider does not switch
the OCR implementation. See [the complete OCR configuration](/ai/ocr).

## Semantic retrieval provider

`search.semantic.embedder` is a **Meilisearch configuration array**, not a PHP
callback/class and not a Laravel AI model ID. Studio accepts `huggingFace`,
`openAi` and `rest` embedder sources. Secrets and provider-specific templates stay
in the server/engine configuration. Configure the engine's model and template,
then apply settings and index documents as described in [semantic search](/semantic-search).
Changing an embedder model can require re-embedding existing documents.

## Assistant models through Laravel AI

Configure provider credentials in the host's `config/ai.php`, using the supported
provider drivers from the installed Laravel AI version. Studio exposes an explicit
allowlist of model choices:

```php
'assistant' => [
    'enabled' => true,
    'connection' => 'redis',
    'queue' => 'media-studio-ai',
    'max_active_runs' => 2,
    'models' => [
        'fast' => [
            'label' => 'Fast',
            'provider' => 'openai',
            'model' => env('STUDIO_ASSISTANT_FAST_MODEL'),
            'usage_weight' => 1,
        ],
        'careful' => [
            'label' => 'Detailed',
            'provider' => 'anthropic',
            'model' => env('STUDIO_ASSISTANT_DETAILED_MODEL'),
            'usage_weight' => 3,
        ],
    ],
],
```

Set both model environment variables to actual models available to your accounts.
`provider` names resolve through Laravel AI, not through the visual adapters.
Provider/model compatibility with images, PDF attachments, streaming and tool
calling must match the selected model. Configuring a driver is not a guarantee
that every model supports every attachment/tool.

At most 32 model definitions are considered. Choice IDs use 1–80 lowercase
letters, digits, underscores or hyphens. Each valid model needs string `label`,
`provider` and `model`. Public configuration exposes choice ID/label and a
`usageWeight` clamped to 1–5; this is not a real currency price or billing ledger.
No browser-side API key is required.

## Provider changes

After changing cached Laravel configuration, rebuild it and restart relevant
workers. Test new models/providers with a bounded input. Existing ready analyses
and queued recipes retain their own profile/version identity; a different model
or recipe is not a request to overwrite historical output silently. External
provider requests may incur costs even when a response is interrupted.
