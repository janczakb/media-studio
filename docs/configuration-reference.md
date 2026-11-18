---
title: "Configuration reference and feature matrix"
description: "An inventory of shipped defaults, optional capabilities and the steps needed to enable them."
---

The package ships **344 configuration leaves** in `config/media-studio.php`, counting empty registration arrays as settings. The three references below cover each shipped leaf with its default and purpose. Environment variables can change those defaults. Credentials shown as `null` are intentionally unset, not a request to put secrets into source control.

| Reference | Settings covered |
| --- | --- |
| [Core configuration](/configuration-core) | Identity, workspaces, access, storage, encryption, mail, sharing, retention and navigation |
| [Processing and AI](/configuration-processing) | Images, PDF/Office/text thumbnails, video, audio, application-file thumbnails, extraction, OCR, semantic search and assistant |
| [Transfers and integrations](/configuration-integrations) | Upload sessions, S3 multipart, REST, MCP, browser extension, Slack and CDN delivery |

These tables document package configuration. Nested registration objects and public component APIs have separate contracts: use [Filament](/filament), [FLEX Fields](/flex-fields), [React SDK](/api/sdk), [image recipes](/image-rendition-presets), [external renditions](/external-renditions), [custom filters](/custom-library-filters) and the guide linked beside each configuration group.

## Optional capability matrix

Enabling a switch does not install an executable, grant an ability, start a worker or configure a cloud account. Match all requirements in the relevant guide.

| Capability | Shipped state | Enable and deploy |
| --- | --- | --- |
| Native image conversions | Enabled; queue execution | Configure encoder and processing worker; [image recipes](/image-rendition-presets) |
| PDF first-page thumbnails | Disabled globally | Enable document thumbnails; Poppler or PDF-enabled libvips; [PDF guide](/document-thumbnails) |
| Office thumbnails | Not registered | Register MIME types and LibreOffice generator on an isolated worker; [Office guide](/document-thumbnails#office-documents) |
| TXT, Markdown, text-only HTML thumbnails | Disabled per MIME | Enable MIME mapping and local Unicode font; [text thumbnails](/document-thumbnails#text-markdown-and-text-only-html) |
| Video posters | Video processing disabled | Enable video processing and FFmpeg on an asynchronous connection; [video thumbnails](/video-thumbnails) |
| Compressed video playback | Disabled | Enable processing.video.compress and encoder; [video processing](/video-thumbnails) |
| Video trimming | Disabled | Independent video_editing settings and worker; [trim videos](/video-editing) |
| Audio waveforms | Enabled | FFmpeg and audio-waveforms worker; [audio previews](/audio-previews) |
| Application-file thumbnails | Disabled | Enable application sources and bounded thumbnail cache; [application thumbnails](/application-files-thumbnails) |
| Image/video AI labels | Disabled | Enable media kinds, provider credentials and automatic admission if desired; [AI labels](/ai-image-labels) |
| Content extraction | Disabled | Enable extraction and worker; PDF and OCR have independent switches; [content indexing](/search-content-indexing) |
| Semantic search | Disabled | Supply an embedder and indexing lifecycle; [semantic search](/semantic-search) |
| Meilisearch | Disabled | Configure server, credentials and index lifecycle; [search](/search) |
| Conversational assistant | Disabled | Configure Laravel AI models and an asynchronous worker; [assistant](/assistant) |
| Resumable uploads | Disabled | Enable sessions and maintain staging/expiry; [resumable transfers](/upload-resumable) |
| Direct S3 multipart | Disabled | Enable sessions and multipart, private S3 and client direct mode; [S3 multipart](/upload-s3-multipart) |
| Browser extension | Disabled | Enable integration, pairing and scoped access; [extension setup](/browser-extension/install) |
| REST API | Disabled | Enable routes and issue scoped token; [REST API](/api/introduction) |
| MCP | Disabled | Configure connection/authentication, tools and permissions; [MCP access](/mcp-access) |
| Slack | Disabled | Optional integration migrations, OAuth and scheduled processing; [Slack](/slack) |
| CDN delivery | Disabled | Configure named adapters/profiles and authorization; [CDN delivery](/cdn-delivery) |
| Guest-upload links | Disabled | Enable link policy and bounds; [guest uploads](/guest-uploads) |
| Background ZIP archives | Disabled | Enable archive storage and worker; [archives](/archive-downloads) |
| Trash expiry | Disabled | Opt into retention days and maintenance; [trash lifecycle](/trash) |
| Web push | Disabled | Configure VAPID credentials and delivery; [notifications](/notifications) |
| Encryption | off policy | Select supported infrastructure/application policy and keys; [encryption](/encryption) |
| Standalone accounts | Disabled | Enable auth, optionally registration and configure mail; [accounts](/standalone-accounts) |
| Filament / FLEX Fields | Optional packages | Explicit adapter configuration; standalone Studio does not require either package |

## Additional runtime overrides

The current implementation also reads the following overrides, although the published default array does not declare them. They are documented separately so that this inventory does not silently omit them.

| Setting | Runtime fallback | Behavior |
| --- | --- | --- |
| `processing.native.dispatch_retry_seconds` | `600` | Delay before redispatching eligible rendition work; minimum 30 seconds. Does not reset max_attempts. |
| `assistant.attachment_directory` | `storage_path('app/private/media-studio-assistant')` | Private local staging area for assistant attachments; requires writable space and the attachment lifecycle's cleanup. |
| `upload.extensions` | Current browse extension list | Array restricting allowed extensions, intersected with browse choices and workspace restrictions; do not set null. See [accepted upload types](/uploads). |
| `browse.extensions` | Built-in category extensions | Overrides extension choices exposed by browse filtering; this does not grant permission to upload a disallowed type. |

## Apply configuration deliberately

Merge the example's keys into the matching section of the published configuration. A shortened example is not a replacement for the entire file. Keep credentials in environment/deployment secrets. After changing cached Laravel configuration, rebuild it and restart long-running workers so they receive the new settings:

```bash
php artisan config:cache
php artisan queue:restart
```

Queued rendition recipes retain their admitted settings. Configuration changes alone do not regenerate existing media. Use [bounded backfill and reconciliation](/rendition-processing), and validate an output before expanding the batch. Cloud integrations, codecs and application resolvers require verification in your deployment; a documentation inventory is not an integration benchmark.
