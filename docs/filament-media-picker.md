---
title: "Studio Media Picker"
description: "Select, order and reuse versioned media in a Filament form."
---

## Selected files in Studio Media Picker

The field supports list and grid presentations. Users can switch between them
without changing their selection. Set the initial layout when defining the field.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker;

StudioMediaPicker::make('gallery')
    ->studio($workspaceId, '/studio-assets/studio.js', '/studio-assets/studio.css')
    ->multiple()
    ->maxFiles(8)
    ->layout('grid')
    ->reorderable()
    ->downloadable()
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
```

Use `layout('list')` for rows with thumbnails, names and sizes. Grid view shows
square thumbnails and file-type illustrations. Reorder selected files by dragging
the handle or focusing it and using the arrow keys. The field stores the same
versioned Studio references in both layouts.

To enforce one layout, hide the switch with `layoutSwitcher(false)`:

```php
StudioMediaPicker::make('gallery')
    ->studio($workspaceId, '/studio-assets/studio.js', '/studio-assets/studio.css')
    ->multiple()
    ->layout('grid')
    ->layoutSwitcher(false);
```

Both `layout()` and `layoutSwitcher()` accept a closure, including Filament
utility injection. The default layout is `list` and the switch is enabled.
Hiding the switch applies the configured layout to every selected file.


### Download and custom file actions

Download buttons are optional and hidden by default. Use `downloadable(false)`
to hide them explicitly, or a closure to control their visibility dynamically.

```php
use Filament\Actions\Action;

StudioMediaPicker::make('gallery')
    ->studio($workspaceId, '/studio-assets/studio.js', '/studio-assets/studio.css')
    ->multiple()
    ->downloadable(false)
    ->fileActions([
        Action::make('review')
            ->label('Review file')
            ->icon('heroicon-o-eye')
            ->iconButton()
            ->requiresConfirmation()
            ->action(function (array $file): void {
                // Use the authorized file's assetId, versionId and name.
            }),
    ]);
```

Custom actions appear beside each selected file in both layouts. Native Filament
action configuration controls their icon, label, visibility, disabled state,
confirmation and modal schema. Closure handlers receive `$file`, resolved on
submission against the current selection and workspace access. Native action
injections such as `$component`, `$arguments` and `$livewire` remain available.
The pinned identity is also available in `$arguments['mediaReference']`.

## Restrict a PDF field to a folder

```php
StudioMediaPicker::make('attachments')
    ->studio($workspaceId, $moduleUrl, $stylesheetUrl)
    ->defaultFolder($approvedFolderId)
    ->scopedFolder($documentsRootId)
    ->acceptedFileTypes(['application/pdf'])
    ->multiple()
    ->minFiles(1)
    ->maxFiles(5)
    ->allowUpload(false)
    ->studioThumbnailPreset('document-card')
    ->required();
```

| Option | Behavior |
| --- | --- |
| `studio()` | Workspace ULID or a Filament closure. Supply module and optional style URL. |
| `acceptedFileTypes()` | Optional MIME allowlist. Exact types and category patterns are supported. |
| `defaultFolder()` | Initial folder. Can be a closure. |
| `scopedFolder()` | Restrict browsing and selection to this subtree. Can be a closure. |
| `multiple()`, `minFiles()`, `maxFiles()` | Single selection by default. Multiple selection defaults to a maximum of 24. Set a maximum up to 120. |
| `allowUpload()` | Off by default. Enable Studio uploads from the modal when the workspace and user permit them. |
| `reorderable()`, `circular()` | Opt into reordering or circular previews. |
| `studioThumbnailPreset()` | Named ready image rendition, closure, or null for the configured thumbnail role. |
| `configureStudioPickerUsing()` | Customize the underlying [StudioPicker](/filament-picker-field). |

Persist a nullable string for one file or cast the model attribute to array for
multiple files. [References and picker behavior](/filament-media-references)
explains cancellation, count validation, stale references and usage tracking.
