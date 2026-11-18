---
title: "Configure FLEX Media Studio"
---

Media Studio runs as a Laravel media library with an embeddable React interface.
Spatie Laravel Permission v8 is a required dependency. Filament, FLEX Fields,
Spatie Media Library v11 and a read-only tag mapping for external sources are optional integrations. This
checkout is currently a development preview; it has not been published as a release.

| Configure | Guide |
| --- | --- |
| Identity and access | [Authentication](/authentication), [members and permissions](/members), [tenancy](/tenancy) |
| Library and interface | [Workspace settings](/workspace-preferences), [embedding and branding](/embedding) |
| Storage and delivery | [Local storage](/storage-local), [private S3](/storage-s3), [CDN](/cdn-delivery), [encryption](/encryption) |
| Media lifecycle | [Upload options](/uploads), [processing configuration](/processing-configuration), [application references](/media-references) |
| Optional services | [Search](/search), [semantic search](/semantic-search), [assistant](/assistant), [Slack](/slack), [MCP](/mcp) |

## Complete settings inventory

Use the [configuration reference and capability matrix](/configuration-reference)
for all 344 shipped settings, default values, additional runtime overrides and
links to examples. Thumbnail generation is documented independently for
[images](/image-rendition-presets), [PDF/Office/text](/document-thumbnails),
[video](/video-thumbnails) and [application-owned files](/application-files-thumbnails).

## Workspace storage

Publish the configuration and migrations explicitly before creating workspaces.
Installing the provider does not automatically create tables or scan storage.

```php
// config/media-studio.php
return [
    'workspace_creation' => true,
    'principals' => [
        'user' => App\Models\User::class,
        // 'employee' => App\Models\Employee::class,
    ],
    'storage' => [
        'disk' => 'local',
        'root' => 'media-studio',
    ],
];
```

Use the alias of the actual authenticated model. String, UUID and ULID user keys
are preserved. A workspace records its disk/root when it is created; changing the
default does not move existing files to another disk.

Storage remains private. A library card carries a stable asset ID and version ID;
applications should store these references rather than a temporary storage URL.

## Workspace availability and limits

[Continue to Workspace and viewer settings](/workspace-preferences#workspace-availability-and-limits).

## Language and timezone preferences

[Continue to Workspace and viewer settings](/workspace-preferences#language-and-timezone-preferences).

## Optional old-chat retention

[Continue to Assistant retention](/assistant-retention#optional-old-chat-retention).

## Notification inbox

[Continue to the dedicated guide](/notifications).

## Image thumbnails and workers

[Continue to Configure processing and workers](/processing-configuration#image-thumbnails-and-workers).

## Recover interrupted upload writes

[Continue to the dedicated guide](/upload-recovery).

## Store references in your own application

[Continue to Reference Studio media from your app](/media-references#store-references-in-your-own-application).

## Branding and embedding

[Continue to Embed and customize Studio](/embedding#branding-and-embedding).

## Optional integrations

- [Configure file and folder transfers, chunks and direct S3 multipart](/uploads).
- [Connect Media Studio to Filament editors](/filament-editors).
- [Browse files and conversions from an existing Spatie Media Library](/spatie-media-library).
- [Create native image conversions or reuse existing thumbnail systems](/image-conversions).
- [Organize files with favorites, recent items and bulk actions](/library-organization).

- [Configure filters and custom sorters](/filters-and-sorting).
- [Use optional native Filament actions](/filament-actions).
- [Configure tenant isolation](/tenancy).

## Settings and workspace permissions

[Continue to Workspace and viewer settings](/workspace-preferences#settings-and-workspace-permissions).

## Restore a library view from its URL

[Continue to Workspace and viewer settings](/workspace-preferences#restore-a-library-view-from-its-url).

## Workspace member profiles

[Continue to Workspace and viewer settings](/workspace-preferences#workspace-member-profiles).

### Member permissions and Redis

[Continue to Workspace and viewer settings](/workspace-preferences#member-permissions-and-redis).

## Preview action bar

[Continue to Embed and customize Studio](/embedding#preview-action-bar).

## Optional AI client connections

The **AI** navigation item and optional Laravel MCP server are documented in
[MCP connections](/mcp). The server is disabled by default and has its own
Spatie permissions. It does not require Filament or enable image analysis.

## Configure navigation and page panels

[Continue to Embed and customize Studio](/embedding#configure-navigation-and-page-panels).

## Document previews

[Continue to Embed and customize Studio](/embedding#document-previews).

## Primary color and viewer profile

[Continue to Embed and customize Studio](/embedding#primary-color-and-viewer-profile).

## Authentication: host model, Filament and standalone installation

[Continue to Host and standalone authentication](/authentication#authentication-host-model-filament-and-standalone-installation).

## Optional encryption policy

`encryption.mode` defaults to `off`. Select native infrastructure policies, portable application file encryption (currently experimental), or a hybrid setup. Named-disk policies can inherit provider defaults or request SSE-S3/SSE-KMS/DSSE-KMS. Volume/database protection remains an explicit deployment responsibility. See [encryption configuration](encryption.md) for supported scope and examples.
