---
title: "RichEditor"
description: "Insert authorized Studio images using the native Filament RichEditor."
---

## RichEditor

Register the plugin on the field and add its tool to your toolbar groups. Keep
the existing toolbar tools you want to expose.

```php
use Filament\Forms\Components\RichEditor;
use UseFlex\MediaStudio\Integrations\Filament\RichEditorStudioPlugin;

RichEditor::make('body')
    ->plugins([
        new RichEditorStudioPlugin(
            workspace: $workspaceId,
            moduleUrl: '/assets/media-studio/studio.js',
            stylesheetUrl: '/assets/media-studio/studio.css',
        ),
    ])
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        ['h2', 'h3', 'bulletList', 'orderedList'],
        [RichEditorStudioPlugin::TOOL],
        ['undo', 'redo'],
    ]);
```

Open **Media Studio**, preview a file and choose **Select**. Enter optional
alternative text for an image, choose **Original** or an available generated image version, and click **Insert media**. The image is inserted through the editor's native command at the saved selection.

## Configuration

| Option | Default and behavior |
| --- | --- |
| `workspace` | Required workspace ULID or an explicit allowlist of up to 20 workspace ULIDs. |
| `moduleUrl`, `stylesheetUrl` | Browser module URL and optional stylesheet URL. Serve the matching rich-editor-extension.js beside the module. |
| `acceptedMimeTypes` | Supported inline images. Use exact types or a category pattern such as image/*. Pass null to allow other files as links. |
| `allowUpload` | True. Set false to browse and insert existing files only. Native editor attachment controls are configured separately. |
| `imageSizes` | (max-width: 768px) 100vw, 1000px. Set this to match the rendered content width. |
| `persistAttachmentIds` | False. Enable durable Studio attachment IDs and use the plugin when rendering saved content. |

### Choose existing images only

```php
new RichEditorStudioPlugin(
    workspace: [$marketingWorkspaceId, $productWorkspaceId],
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
    acceptedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
    allowUpload: false,
);
```

### Render saved content

Reuse the Studio plugin configuration from your form with Filament's renderer,
especially when persisting attachment IDs or responsive attributes.

```php
use Filament\Forms\Components\RichEditor\RichContentRenderer;

$html = RichContentRenderer::make($article->body)
    ->plugins([$studioPlugin])
    ->toHtml();
```

## Persist private attachment IDs

```php
$studioPlugin = new RichEditorStudioPlugin(
    workspace: $workspaceId,
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
    persistAttachmentIds: true,
);

RichEditor::make('body')
    ->json()
    ->plugins([$studioPlugin])
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        [RichEditorStudioPlugin::TOOL],
    ]);
```

This mode keeps the workspace, asset, version and optional rendition in the
image attachment ID. The provider resolves the delivery URL in the current
application and tenant context. This mode uses the selected original or
rendition, without generating a responsive set. Use the Studio picker for
attachments. The Studio attachment provider rejects direct native file uploads
and leaves deletion of shared Studio media to its normal lifecycle.

## Responsive images in RichEditor

The native RichEditor image schema uses `img`, rather than a `picture` node.
Studio adds `srcset` and `sizes` through an optional editor extension, so browsers
can choose an appropriate ready conversion. Only available variants of the same
encoding and distinct widths are included. If fewer than two are available, the
selected original or variant remains an ordinary image. No conversions are
created while selecting or rendering content.

Publish **rich-editor-extension.js** from the React distribution beside the
configured Studio module. Keep this plugin installed when editing and rendering
saved rich content so responsive attributes survive serialization. The largest
candidate is the fallback `src`. Set `imageSizes` for the actual content width:

```php
new RichEditorStudioPlugin(
    workspace: $workspaceId,
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
    imageSizes: '(max-width: 700px) 100vw, 700px',
);
```

Standard Markdown has no `srcset`/`picture` syntax. Its adapter keeps a standard
image pointing to the selected variant. A custom responsive Markdown renderer is
an application concern; this adapter does not insert raw HTML that a Markdown
renderer might strip or display as text.

See [Editor access, versions and retention](/filament-editors#access-and-retention)
for storage, publishing and usage registration.
