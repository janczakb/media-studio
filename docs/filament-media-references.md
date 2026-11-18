---
title: "Media references and validation"
description: "Persist Studio references, track usage and handle removed files."
---

`StudioMediaPicker`, native `StudioFileUpload` and FLEX Fields FileUpload store
a pinned `assetId:versionId` string. Multiple fields store an ordered array of
the same strings. Treat these as identifiers, not filesystem paths or URLs.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;

$reference = is_string($campaign->cover) ? StudioReference::parse($campaign->cover) : null;
if ($reference !== null) {
    $assetId = $reference['assetId'];
    $versionId = $reference['versionId'];
}
```

For an Eloquent gallery, use a JSON column and cast it to `array`. In the same
transaction as the owner record save, [register and replace its usages](/configuration#store-references-in-your-own-application).
Resolving or choosing an existing reference does not reupload the original.

### Save a gallery with its usages

Register the owning model in `media-studio.usage_owners`, and route the
validated form state through the same transaction as the usage update. This
example assumes `$campaign` is the authorized owner, the model casts
`gallery` to an array, and `$workspaceId` was resolved server-side.

```php
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;
use UseFlex\MediaStudio\Library\MediaReferences;

DB::transaction(function () use ($campaign, $values, $workspaceId): void {
    $campaign->fill($values)->saveOrFail();

    $references = [];
    foreach (array_values($campaign->gallery ?? []) as $index => $value) {
        $reference = is_string($value) ? StudioReference::parse($value) : null;
        abort_if($reference === null, 422, 'Invalid Studio gallery reference.');
        $references[] = [...$reference, 'slot' => 'image-'.$index];
    }

    app(MediaReferences::class)->sync(
        actor: auth()->user(),
        owner: $campaign,
        attribute: 'gallery',
        workspaceId: $workspaceId,
        references: $references,
    );
});
```

An empty gallery passes an empty references array and clears that attribute's
previous usages. Keep the model's update policy and the Studio workspace
policy in effect. The sync resolves the entire selection again on the server.

## Deleted or unavailable media

Deleted Studio files are removed from the field selection when the form loads
and again before validation/save. For example, if two of five selected assets
are trashed in Studio, only the three available references remain; no broken
preview tiles are rendered and saving does not fail because of those deleted
references. This applies to native StudioFileUpload, its optional FLEX Fields
adapter and StudioMediaPicker. Normal required/minimum-count rules still apply
to the remaining selection. Workspace access or backend failures do not clear
the selection. Removing a reference never reuploads or deletes another asset.

### Picker constraints and thumbnail conversions

Native StudioFileUpload, FlexFileUpload::mediaStudio() and StudioMediaPicker
share `studioThumbnailPreset()`. Pass a preset name, a closure or null to use
Studio's configured thumbnail role. The named rendition must already be generated
or registered in Studio. Opening a field never generates conversions or scans
storage. Missing thumbnails show file icons; originals are loaded only for an
explicit preview/download, never as a thumbnail fallback.

```php
FlexFileUpload::mediaStudio('photos')
    ->studio($workspaceId, $moduleUrl, $stylesheetUrl,
        acceptedMimeTypes: ['image/*'])
    ->studioThumbnailPreset('picker-card')
    ->multiple()->minFiles(1)->maxFiles(8);

StudioMediaPicker::make('documents')
    ->studio($workspaceId, $moduleUrl, $stylesheetUrl)
    ->acceptedFileTypes(['application/pdf'])
    ->studioThumbnailPreset('document-card')
    ->multiple()->minFiles(1)->maxFiles(5);
```

MIME restrictions accept exact types and category patterns such as image/*.
Folder rows remain visible for navigation. Native acceptedFileTypes() restrictions
also reach the modal; combined native/Studio restrictions use their intersection
for browsing and both are enforced on save. Incompatible restrictions reject
configuration instead of showing all file types.

The modal starts with existing references selected. These count toward the total
maximum across folders. At capacity, unselected files are disabled; deselecting
a file frees a slot. Multiple fields with maxFiles(1) retain this hard limit.
Confirmation replaces the field with the complete edited selection. Cancellation
preserves its old value. Minimum/maximum counts are checked in modal validation
and the parent form. An optional field with minimum zero can be cleared.
