---
title: "References, usages and removed files"
description: "Persist Studio-backed FLEX values and preserve ownership across form edits."
---

A selected Studio file is stored as an `assetId:versionId` string. A multiple field stores an ordered array. Choosing a file already in Studio resolves that reference. Upload file sends new bytes through Studio, which owns its storage and processing.

## Store the form value

For a multiple field, use a JSON-capable attribute with an array cast. Save validated form state and register its media usages together. Follow the complete [transactional usage example](/filament-media-references#save-a-gallery-with-its-usages), including registration in `media-studio.usage_owners`.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;

$reference = StudioReference::parse($storedValue);

if ($reference !== null) {
    $assetId = $reference['assetId'];
    $versionId = $reference['versionId'];
}
```

Use the resolved identifiers when registering usage. Fetch authorized delivery URLs when rendering. Expiring object-store addresses cannot serve as persistent references.

## Handle removed files

The field removes deleted Studio references during hydration and again before validation/save. If two of five selected assets are trashed, the remaining three are shown. Normal required and minimum-count rules still apply. Workspace access failures and backend errors require resolution rather than clearing the field.

Removing a selection from a form leaves the Studio original under Studio's lifecycle. Register or clear the owning record's usages when the record saves. To remove a managed asset itself, use Studio's authorized [trash workflow](/trash).

## Work with external sources

For Spatie-owned or application-owned media, expose the source through [Spatie integration](/spatie-media-library) or [application files](/application-files). Keep the original owner's mutation and cleanup policy. Configure the Studio workspace's [S3 disk](/storage-s3), [encryption](/encryption) and [renditions](/external-renditions) independently of the FLEX tile presentation.
