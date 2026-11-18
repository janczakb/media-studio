---
title: "FLEX Fields integration"
description: "Connect FLEX Fields uploads and rich text to the Studio library."
---

Use FLEX Fields' upload and rich editor components with your Studio workspaces. The upload factory returns a Studio-backed field that inherits the FLEX presentation and action system. Existing library selections keep their asset and version references.

| Task | Guide |
| --- | --- |
| Prepare packages, permissions and assets | [Setup](/flex-fields/setup) |
| Add Upload file and Choose media to a form | [FileUpload](/filament-flex-file-upload) |
| Restrict MIME types, folder access and file counts | [Field configuration](/flex-fields/configuration) |
| Configure thumbnails, classic editing and explicit AI acceptance | [Image editing](/flex-fields/image-editing) |
| Insert Studio images in FlexRichEditor | [Rich editor](/filament-flex-rich-editor) |
| Save references, register usages and handle removed files | [Persistence and lifecycle](/flex-fields/persistence) |
| Diagnose missing assets or unavailable actions | [Assets and troubleshooting](/flex-fields/troubleshooting) |

## Choose the component

```php
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload;

FlexFileUpload::mediaStudio('gallery')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        acceptedMimeTypes: ['image/*'],
    )
    ->multiple()
    ->maxFiles(8)
    ->uploadLayout('grid');
```

The example assumes an authorized workspace ULID resolved on the server and the matching Studio distribution served at the configured paths. New uploads are published immediately by this adapter unless configured otherwise. Choosing a file already in Studio stores a reference to that file.
