---
title: "Filament PHP configuration"
description: "Studio field methods, persistence defaults and configuration scope for Filament and FLEX Fields."
---

Use these Studio methods alongside the installed Filament component's own
schema and validation methods. Workspace IDs, folder IDs and upload policy
come from your host application and Studio access checks.

## Selected media fields

| Component method | Effect |
| --- | --- |
| `StudioMediaPicker::studio($workspace, $moduleUrl, $stylesheetUrl)` | One workspace ULID or closure. Style URL is optional. |
| `acceptedFileTypes($types)` | Exact MIME types, category patterns or null. |
| `defaultFolder($folder)`, `scopedFolder($folder)` | Initial location and separately enforced subtree. Accept a ULID, closure or null. |
| `multiple()`, `minFiles()`, `maxFiles()` | Single selection by default. Default maximum for multiple mode is 24, configurable up to 120. |
| `allowUpload()`, `reorderable()` | Both off by default. Reordering applies to multiple selections. |
| `downloadable()`, `fileActions()` | Download is off by default. Register named Filament actions for selected items. |
| `layout()`, `layoutSwitcher()`, `circular()` | List or grid. Default is list with an enabled layout switch. Use circular thumbnails where appropriate. |
| `studioThumbnailPreset()`, `configureStudioPickerUsing()` | Select a ready variant or customize the shared picker. |

See [StudioMediaPicker](/filament-media-picker) for ordered galleries and
custom item actions. Action callbacks can receive the authorized
`mediaReference` from the current selection.

## Upload fields

[StudioFileUpload](/filament-studio-file-upload) uses native Filament.
[FlexFileUpload::mediaStudio()](/filament-flex-file-upload) uses the optional
FLEX Fields presentation. Both support the
[shared configuration](/filament-upload-options).

- `studio()` takes workspace, module and optional stylesheet URL, optional
  upload folder and MIME types, and boolean defaults for library selection
  and rendition generation. It configures the Studio destination independently
  of Filament's native upload storage.
- `studioUpload()`, `studioLibrarySelection()`, `studioUploadFolder()`,
  `studioBrowseFolder()` and `studioScopedFolder()` control upload and browse
  scope. Explicit upload and browse folders must satisfy the configured subtree.
- `persistStudioUploadsImmediately()` accepts a boolean or closure. Native
  StudioFileUpload publishes on form save by default. FLEX publishes completed,
  admitted uploads immediately by default. Both keep selection state as pinned
  references.
- `studioGenerateRenditions()`, `studioThumbnailPreset()` and
  `configureStudioPickerUsing()` control generation, thumbnail selection and
  picker presentation respectively. Generation can be disabled independently
  of displaying a ready thumbnail.

The FLEX adapter adds `studioImageEditor()`, `studioAiImageEditor()` and
`studioInlineEditedThumbnail()` for application-owned image version
publication. `deleteReplacedFiles()` passes the field's replacement policy to
the classic publisher. An AI publisher reads it through
`shouldDeleteReplacedFiles()`. Configure both callbacks and use the same
publisher when they should share policy. See
[thumbnail generation after edits](/filament-flex-file-upload#configure-thumbnails-after-an-image-edit)
and [version history](/filament-flex-file-upload#keep-only-the-current-edited-image).

## Custom action picker

`StudioPicker` is a low-level field for an application-owned action.
`studio()` accepts one ULID or an explicit list of up to 20 workspaces.
`acceptedMimeTypes()`, `allowUpload()` and `folders()` define its browsing
and upload options. `selectionLimit()` enables multi-selection and bounds it
to 1–120. `selectedReferences()` seeds pinned references. `thumbnailPreset()`
and `selectionMessage()` configure thumbnails and validation copy.

`studioOptions()` accepts the following serializable presentation keys.
`layout`, `locale`, `localization`, `browse`, `comments`,
`pdfPreview`, `documentPreview`, `imagePreview`, `videoEditing`,
`previewActions`, `previewMenu`, `previewTitleEditable`,
`videoPreviewSkin`, `primaryColor` and `branding`.
Use the dedicated methods for workspace, scope, MIME and upload constraints.
See [custom picker setup](/filament-picker-field) for submit validation and
the single-file selection resolver.

## Editors and displays

| Integration | Configuration |
| --- | --- |
| [RichEditor](/filament-rich-editor) and [FLEX Rich Editor](/filament-flex-rich-editor) | `RichEditorStudioPlugin` accepts workspace scope, module and stylesheet URLs, responsive `imageSizes`, `acceptedMimeTypes`, `allowUpload` and `persistAttachmentIds`. |
| [MarkdownEditor](/filament-markdown-editor) | `MarkdownStudio::attach()` accepts the editor, workspace scope, asset URLs, MIME filter and upload flag. Markdown persists the selected authenticated URL. |
| [Media Entry](/filament-media-entry) and [Media Column](/filament-media-column) | `studio()` resolves the workspace. Use Filament image sizing, rounded/circular options and `openStudioMedia()` for an authorized link. |
| [Filament actions](/filament-actions) | One reference for individual and bulk actions, including workspace binding, entry resolution and result callbacks. |

Default editor MIME types are JPEG, PNG, WebP, AVIF and GIF. Editor insertion
still requires a supported inline image. Restrict the list for your use case
and disable uploads with `allowUpload: false` when editors should use only
existing media.
