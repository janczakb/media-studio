---
title: "FLEX Rich Editor"
description: "Use Studio with the optional FLEX Fields rich text editor."
---

## FlexRichEditor

The same plugin supports FLEX Fields' `FlexRichEditor`, which extends the native Filament editor. Studio does not replace its editing engine or upload configuration:

```php
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexRichEditor;
use UseFlex\MediaStudio\Integrations\Filament\RichEditorStudioPlugin;

FlexRichEditor::make('body')
    ->plugins([
        new RichEditorStudioPlugin(
            workspace: $workspaceId,
            moduleUrl: '/assets/media-studio/studio.js',
            stylesheetUrl: '/assets/media-studio/studio.css',
        ),
    ])
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        [RichEditorStudioPlugin::TOOL],
        ['undo', 'redo'],
    ]);
```

The example explicitly chooses toolbar buttons; include the other FLEX tools your application needs. Selecting an existing Studio image inserts a reference URL through the native command. It does not upload the image again or run FLEX's attachment conversion pipeline a second time. FLEX Fields remains optional for applications using only native Filament editors.

## Limit selection to an approved library

```php
FlexRichEditor::make('body')
    ->plugins([
        new RichEditorStudioPlugin(
            workspace: $workspaceId,
            moduleUrl: '/studio-assets/studio.js',
            stylesheetUrl: '/studio-assets/studio.css',
            acceptedMimeTypes: ['image/webp', 'image/jpeg'],
            allowUpload: false,
        ),
    ])
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        [RichEditorStudioPlugin::TOOL],
    ]);
```

This field uses the same [plugin options and render pipeline](/filament-rich-editor)
as RichEditor. Keep your existing FLEX Fields editor options on the field and
include the Studio tool alongside the available native tools.


## Save and render private references

Set `persistAttachmentIds: true` to persist the workspace, asset and version in attachment IDs. Keep the same plugin installed when rendering saved JSON.

```php
use Filament\Forms\Components\RichEditor\RichContentRenderer;

$studioPlugin = new RichEditorStudioPlugin(
    workspace: $workspaceId,
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
    allowUpload: false,
    persistAttachmentIds: true,
);

$field = FlexRichEditor::make('body')
    ->json()
    ->plugins([$studioPlugin])
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        [RichEditorStudioPlugin::TOOL],
        ['undo', 'redo'],
    ]);

$html = RichContentRenderer::make($article->body)
    ->plugins([$studioPlugin])
    ->toHtml();
```

The example assumes the imports from the first example and an authorized article. Use the Studio tool for attachment selection in this mode. The Studio attachment provider rejects native direct attachment uploads. Configure the host's rendered content policy and current viewer authorization as part of your application.

## Use ready responsive conversions

With the default URL-based insertion mode, `imageSizes` controls the rendered image width hint. Studio uses available ready conversions of the same encoding to build responsive attributes. Keep `rich-editor-extension.js` available beside the module and keep the plugin in the render pipeline. See [responsive image behavior](/filament-rich-editor#responsive-images-in-richeditor).

For access and asset ownership, follow [editor retention](/filament-editors#access-and-retention). For missing scripts or styles, use [FLEX integration diagnostics](/flex-fields/troubleshooting).


## Configure the editor's media behavior

| Option | Behavior |
| --- | --- |
| `workspace` | One approved workspace or an allowlist of up to 20 workspace IDs |
| `acceptedMimeTypes` | Supported inline image MIME types by default |
| `allowUpload` | Enable upload in the Studio picker, true by default |
| `persistAttachmentIds` | Store a durable Studio attachment identity, false by default |
| `imageSizes` | Responsive image sizing for the rendered article width |

## Render saved FLEX content

Use the same plugin configuration when rendering JSON content. This lets the attachment provider and responsive image extension resolve the saved content.

```php
use Filament\Forms\Components\RichEditor\RichContentRenderer;

$html = RichContentRenderer::make($article->body)
    ->plugins([$studioPlugin])
    ->toHtml();
```

The `$studioPlugin` variable is the configured `RichEditorStudioPlugin` used by the form. The default inline types are JPEG, PNG, WebP, AVIF and GIF. Pass `acceptedMimeTypes: null` if the application deliberately supports inserting other assets as links. Native editor attachment buttons have their own configuration.

See [private attachment IDs](/filament-rich-editor#persist-private-attachment-ids) for persistence and provider behavior, and [FLEX assets](/flex-fields/troubleshooting) when the integration's scripts or styles fail to load.
