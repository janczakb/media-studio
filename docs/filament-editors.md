---
title: "Editors"
description: "Choose the Studio adapter for your Filament text editor."
---

- [RichEditor](/filament-rich-editor) offers native insertion, rendition selection and responsive image markup.
- [MarkdownEditor](/filament-markdown-editor) inserts at the saved CodeMirror cursor.
- [FLEX Rich Editor](/filament-flex-rich-editor) uses the same Studio plugin with the optional FLEX editor.
- [Studio FileUpload](/filament-studio-file-upload) and [Studio Media Picker](/filament-media-picker) store media references in separate fields.

Serve the matching Studio JavaScript and stylesheet. See [setup](/filament#setup).

## Choose an image version

Both editor actions offer **Image version** after a file is selected. The default is **Original**. Available generated image variants are listed by preset name and dimensions. Markdown inserts the selected stable, authenticated version URL. RichEditor may add a responsive set of ready image variants as described in the [RichEditor guide](/filament-rich-editor#responsive-images-in-richeditor). Pending, failed or unavailable variants are not listed. Studio validates the chosen variant again when the action submits, so a stale selection cannot insert an unavailable conversion.

If a variant is later removed or its source access is revoked, the saved URL will stop resolving. Keep variants used by saved content and register their usages in your record's save transaction. Choosing a variant does not itself create an owner relationship for an unsaved form.

## Access and retention

Choose the workspace server-side when defining the field. Studio rechecks access
to the exact file version when the action submits. By default, the editor stores a stable
application URL to that version. RichEditor can also persist an attachment ID. The URL requires workspace access whenever it
is opened, including when it appears in rendered content. To show media on a
public page, configure the application's publication and access rules separately.

When your application saves its record, [register media usages](/configuration#store-references-in-your-own-application)
in the same transaction to protect the selected files from removal. The editor
action itself inserts content into an unsaved form.

## A compact modal picker

Editor actions use a dedicated picker layout, without the application navigation,
account menu or workspace administration. Search, filters, folders, upload and
layout controls remain available. The filters and selected-file actions stay
visible during scrolling. Opening an image displays Studio's preview inside the
same modal, with filename, zoom and file navigation. Close the preview to return
to the grid.

## Select from several workspaces

Pass one workspace ID to restrict the picker to that workspace, or an explicit
array of up to 20 workspace IDs to enable the compact workspace selector beside
search. Resolve these IDs for the current user on the server. Every selected file
is reauthorized when inserted; a client-supplied workspace ID cannot expand this
configured scope. Switching workspace clears the selected file and folder path.

```php
new RichEditorStudioPlugin(
    workspace: [$marketingWorkspaceId, $productWorkspaceId],
    moduleUrl: '/studio-assets/studio.js',
    stylesheetUrl: '/studio-assets/studio.css',
);

MarkdownStudio::attach(
    MarkdownEditor::make('body'),
    [$marketingWorkspaceId, $productWorkspaceId],
    '/studio-assets/studio.js',
    '/studio-assets/studio.css',
);
```


## FileUpload

See [Studio FileUpload](/filament-studio-file-upload) for native upload setup.

## RichEditor

See [RichEditor](/filament-rich-editor) for setup and examples.

## MarkdownEditor

See [MarkdownEditor](/filament-markdown-editor) for setup and examples.

## FlexRichEditor

See [FLEX Rich Editor](/filament-flex-rich-editor) for setup and examples.
