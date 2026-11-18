---
title: "StudioPicker field"
description: "Build a custom Filament action around the shared Studio modal."
---

Use the low-level `StudioPicker` field when your own action controls
confirmation and persistence. For a ready-to-use model field, see
[Studio Media Picker](/filament-media-picker).

```php
use Filament\Actions\Action;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;

$types = ['application/pdf'];

Action::make('chooseApprovedDocument')
    ->schema([
        StudioPicker::make('reference')
            ->studio($workspaceId, $moduleUrl, $stylesheetUrl)
            ->acceptedMimeTypes($types)
            ->allowUpload(false)
            ->required(),
    ])
    ->action(function (array $data) use ($workspaceId, $types): void {
        $file = StudioPicker::resolveSelection($workspaceId, $data, $types);
        $this->data['document'] = StudioReference::key($file);
    });
```

The helper expects the submitted field under `reference` and reauthorizes the
asset and version. Use native action authorization for your host record as well.
For a custom folder constraint, validate the selected reference against the
current subtree on submit. For custom multi-selection, validate each item and
the total count server-side. The standard field adapters already enforce those
boundaries and are preferable for ordinary form fields.

| Option | Purpose |
| --- | --- |
| `studio()` | Workspace ULID or allowlist, module URL and optional style URL. |
| `acceptedMimeTypes()` | Exact types or category patterns. Null removes the field filter. |
| `allowUpload()` | Enable or disable picker uploads. Workspace rights still apply. |
| `folders()` | Initial folder and browse scope. Supply server-approved ULIDs. |
| `selectionLimit()`, `selectedReferences()` | Configure the selection cap and seed initial pinned references. |
| `thumbnailPreset()` | Use a named ready thumbnail. |
| `selectionMessage()` | Customize the required-selection message. |
| `studioOptions()` | Set supported serializable UI options. Server scope and authorization remain separate. |

```php
StudioPicker::make('reference')
    ->studio($workspaceId, $moduleUrl, $stylesheetUrl)
    ->allowUpload(false)
    ->folders(default: $folderId, scope: $rootId)
    ->thumbnailPreset('picker-card')
    ->selectionMessage('Choose an approved file.')
    ->studioOptions([
        'previewMenu' => false,
        'previewTitleEditable' => false,
        'documentPreview' => ['print' => false],
    ]);
```
