---
title: "MarkdownEditor"
description: "Insert Studio images and optionally file links at the Markdown cursor."
---

## MarkdownEditor

```php
use Filament\Forms\Components\MarkdownEditor;
use UseFlex\MediaStudio\Integrations\Filament\MarkdownStudio;

MarkdownStudio::attach(
    editor: MarkdownEditor::make('body'),
    workspace: $workspaceId,
    moduleUrl: '/assets/media-studio/studio.js',
    stylesheetUrl: '/assets/media-studio/studio.css',
);
```

Use the **Media Studio** action next to the field label. The picker inserts an
image at the saved CodeMirror selection. If the document changes
while the picker is open, reopen the picker from the insertion point you want.

## Select approved images from two workspaces

```php
MarkdownStudio::attach(
    editor: MarkdownEditor::make('release_notes')->required(),
    workspace: [$marketingWorkspaceId, $productWorkspaceId],
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
    acceptedMimeTypes: ['image/jpeg', 'image/png'],
    allowUpload: false,
);
```

| Option | Default and behavior |
| --- | --- |
| `editor` | Required native MarkdownEditor. The adapter returns that same field. |
| `workspace` | Required workspace ULID or allowlist of up to 20 ULIDs. |
| `moduleUrl`, `stylesheetUrl` | Browser module URL and optional stylesheet URL. |
| `acceptedMimeTypes` | Supported inline images. Pass null to allow non-image files as Markdown links. |
| `allowUpload` | True. Switch off to remove upload and creation from the picker. |

The result is standard Markdown with a stable, authenticated URL. Select an
available rendition in **Image version** to use that conversion. Markdown does
not carry responsive image attributes. See [editor access and retention](/filament-editors#access-and-retention).
