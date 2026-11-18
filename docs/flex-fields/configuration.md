---
title: "Configure FLEX FileUpload"
description: "Set source buttons, layouts, file limits, folder scope and thumbnail presets."
---

Start with `FlexFileUpload::mediaStudio()`, call `studio()`, then apply field-level constraints. Workspace and folder IDs describe Studio objects. Configure the actual filesystem disk, encryption and conversions in the Studio installation.

## Scope uploads and library selection

```php
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload;

FlexFileUpload::mediaStudio('campaign_media')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        uploadFolder: $incomingFolderId,
        acceptedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
    )
    ->studioBrowseFolder($incomingFolderId)
    ->studioScopedFolder($allowedRootId)
    ->multiple()
    ->minFiles(1)
    ->maxFiles(6)
    ->maxSize(10 * 1024)
    ->required();
```

The upload destination and initial folder must be inside the permitted subtree. The scope is enforced when selecting and saving. Files outside accepted MIME types are excluded while folder rows remain available for navigation. At the maximum count, users must deselect a file before adding another. Required and minimum-count validation also apply to the saved form.

## Choose the source buttons

```php
$field->studioUpload(false);            // Choose existing files only.
$field->studioLibrarySelection(false); // Upload files only.
```

These are alternative configurations on an already configured field. Both sources are enabled initially. FLEX normally publishes a new upload immediately. To publish on form save, configure `persistStudioUploadsImmediately(false)`. A file that was already published remains a Studio asset even if the form is later cancelled.

## Configure list and grid

```php
$field
    ->uploadLayout('list')
    ->downloadable(false)
    ->openable()
    ->reorderable();
```

Use `uploadLayout('grid')` for thumbnails. File viewing can be enabled with `fileClickPreview()`; `fileViewer(false)` disables the viewer. Download, open, view and remove controls have separate field options. See [shared upload configuration](/filament-upload-options) for other inherited controls and the effect of form validation.

## Select a named thumbnail

```php
$field
    ->studioGenerateRenditions(true)
    ->studioThumbnailPreset('picker-card')
    ->studioInlineEditedThumbnail(true);
```

Define `picker-card` under `media-studio.processing.renditions`, or register an existing rendition. A missing thumbnail uses the file fallback. Opening the form resolves available presentation data. To generate thumbnails for new uploads or edits, use the appropriate [processing settings](/processing-configuration).

## Customize the Studio modal

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;

$field->configureStudioPickerUsing(
    fn (StudioPicker $picker) => $picker->studioOptions([
        'previewMenu' => false,
        'previewTitleEditable' => false,
        'documentPreview' => ['print' => false],
    ]),
);
```

Keep workspace, folder and MIME restrictions configured on the PHP field. The shared picker exposes the supported React presentation options. The picker used by FileUpload selects existing assets, while the field's Upload file button handles new files.
