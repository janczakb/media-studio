---
title: "Studio FileUpload"
description: "Publish native Filament uploads to a Studio workspace."
---

## FileUpload

`StudioFileUpload` extends Filament's native `FileUpload`. The regular file input
publishes into Studio when the form saves. The optional Media Studio action uses
the same MIME allowlist to pick an existing file without uploading a second copy.
Configure the workspace and destination using trusted record or panel context.
With no upload folder, uploads go to the configured scope root or to the workspace root if unscoped.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioFileUpload;

StudioFileUpload::make('document')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        uploadFolder: $documentsFolderId,
        acceptedMimeTypes: ['application/pdf'],
        allowLibrarySelection: true,
        generateRenditions: true,
    )
    ->maxSize(10 * 1024)
    ->openable();
```

Pass `acceptedMimeTypes: null` for the workspace's usual upload policy, or
list the exact types accepted by this field. The upload destination applies to
new files. Existing selections may come from any readable folder in the configured
workspace unless you restrict them with `studioScopedFolder()`. Uploads use that workspace's storage and configured
conversion pipeline. Set `generateRenditions: false` to skip automatic
renditions for this field. Filament's normal temporary-upload limits still apply.

Store the resulting string in your model. It holds an asset ID and a pinned
version ID. Use `StudioFileUpload::parseReference($value)` to recover both IDs.
With Filament's `multiple()`, persist an array and explicitly set
`maxFiles()`. When removing a file from the form, save the changed references
in your model. Studio files can be shared by other records, so this field does
not delete them.

## Upload new images into a fixed folder

```php
StudioFileUpload::make('gallery')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: $moduleUrl,
        stylesheetUrl: $stylesheetUrl,
        uploadFolder: $incomingFolderId,
        acceptedMimeTypes: ['image/*'],
        allowLibrarySelection: false,
    )
    ->studioScopedFolder($mediaRootId)
    ->studioThumbnailPreset('gallery-card')
    ->multiple()
    ->minFiles(1)
    ->maxFiles(8)
    ->maxSize(10 * 1024);
```

## Choose from a restricted library without direct upload

```php
StudioFileUpload::make('approved_document')
    ->studio($workspaceId, $moduleUrl, $stylesheetUrl)
    ->studioUpload(false)
    ->studioBrowseFolder($approvedFolderId)
    ->studioScopedFolder($documentsRootId)
    ->acceptedFileTypes(['application/pdf']);
```

See [shared upload options](/filament-upload-options) for every Studio-specific
setter, storage, publish timing and validation. For the FLEX Fields presentation,
use [FLEX Fields FileUpload](/filament-flex-file-upload).
