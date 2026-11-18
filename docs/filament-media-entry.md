---
title: "Studio Media Entry"
description: "Display authorized Studio media in a Filament record detail."
---

Use `StudioMediaEntry` in a Filament infolist to display a Studio reference saved by a
[picker](/filament-media-picker) or [upload field](/filament-studio-file-upload).
The state can be a single pinned reference or an array. Resolve the workspace
from the authorized host record.

## Square preview

```php
use App\Models\Campaign;
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaEntry;

StudioMediaEntry::make('cover')
    ->label('Campaign cover')
    ->studio(fn (Campaign $record): string => $record->studio_workspace_id)
    ->square()
    ->imageSize(56)
    ->openStudioMedia()
    ->placeholder('No cover available');
```

## Circular gallery previews

```php
StudioMediaEntry::make('gallery')
    ->label('Selected assets')
    ->studio($workspaceId)
    ->circular()
    ->imageSize(36)
    ->stacked()
    ->limit(4)
    ->limitedRemainingText()
    ->placeholder('No media');
```

| Option | Behavior |
| --- | --- |
| `studio(string or Closure)` | Use the workspace that owns the pinned references. |
| `openStudioMedia(bool $newTab = true)` | Link to the authorized version URL. Omit it for a display-only preview. |
| `square()`, `circular()`, `imageSize()` | Use Filament's native shape and dimensions. |
| `stacked()`, `limit()`, `limitedRemainingText()` | Configure several references in one field. |
| `placeholder()`, `alt()` | Customize empty state and alt text. Studio falls back to stored alt text and then the file name. |

Previews use Studio's default thumbnail rendition and fall back to a document
icon when a readable file has no thumbnail. A deleted or unauthorized reference
does not resolve to a preview URL. The component reads media in the current
panel's authentication context. Even if the host record is visible, the asset's
own policies still apply. Configure the Studio thumbnail role for these display
components. `studioThumbnailPreset()` is for pickers and upload fields.


## Register the entry on a record view

```php
use Filament\Schemas\Schema;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        StudioMediaEntry::make('gallery')
            ->studio(fn (Campaign $record): string => $record->studio_workspace_id)
            ->square()
            ->imageSize(72)
            ->limit(6),
    ]);
}
```

Use an array cast for galleries. The entry batches the visible references for
that record. Use [Studio Media Column](/filament-media-column) in tables.
