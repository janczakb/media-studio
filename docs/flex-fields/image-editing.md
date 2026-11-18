---
title: "Classic and AI image editing"
description: "Publish reviewed edits, refresh thumbnails and configure previous-version cleanup in PHP."
---

The Studio-backed FLEX field supports the native image editor and an optional AI image action. Both need an application-owned publication callback that validates the source and commits the accepted result as a Studio version.

## Connect the classic editor

Configure the following callback on an existing Studio-backed field. `$publishEditedVersion` is your application's callable, responsible for authorizing the reference and returning the new pinned Studio reference.

```php
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

$field->studioImageEditor(
    fn (string $reference, TemporaryUploadedFile $file, bool $deletePreviousVersion): string =>
        $publishEditedVersion($reference, $file, $deletePreviousVersion),
);
```

The callback runs when the native editor submits its output. It receives the prior reference, validated temporary file and evaluated field retention policy. The publisher should recheck workspace, tenant, source version and revision, store the new object, commit its version and return the new reference. See [edited-version lifecycle](/media-versions).

## Connect an AI action

Use `studioAiImageEditor()` to configure the native FLEX AI action. The configuration callback receives an `EditImageWithAiAction`.

```php
use Bjanczak\FilamentFlexFields\Filament\Actions\EditImageWithAiAction;

$field->studioAiImageEditor(
    fn (EditImageWithAiAction $action) => $configureAiAction($action),
);
```

The example delegates to your application's `$configureAiAction` callable. It must configure the source and acceptance callbacks below.

| Action callback | Required application behavior |
| --- | --- |
| `sourceUsing()` | Resolve the field's current file key to an authorized reference and return an `ImageEditPreview`. |
| `workspaceScopeUsing()` | Return the workspace, asset, version and entry revision for the current edit. |
| `sourceImageUsing()` | Read the authorized original into an `ImageEditInput`. |
| `acceptUsing()` | Recheck source scope and revision, publish the user-selected result and replace only its field key with the returned reference. |

FLEX provides `ImageEditPreview` and `ImageEditInput` under `Bjanczak\FilamentFlexFields\Support\Media\ImageEditing`. Generation can produce several candidates. Publication belongs in the acceptance callback, after the user chooses a candidate.

| Action callback | Responsibility |
| --- | --- |
| `sourceUsing()` | Return an authorized `ImageEditPreview` for the selected field reference. |
| `workspaceScopeUsing()` | Bind the run to the authorized workspace, asset, version and revision. |
| `sourceImageUsing()` | Read the authorized original and produce an `ImageEditInput`. |
| `acceptUsing()` | Reauthorize the source, publish the selected temporary result and replace that one field reference. |

Generating an AI candidate leaves the library version unchanged. Publish only after explicit acceptance, such as Use this version. Read `$field->shouldDeleteReplacedFiles()` in the acceptance callback to apply the same PHP policy as the classic editor. The action is available only when the field is enabled and FLEX has configured image-editing models.

## Refresh the selected tile

```php
$field
    ->studioGenerateRenditions(true)
    ->studioThumbnailPreset('thumbnail-v1')
    ->studioInlineEditedThumbnail(true);
```

A custom publisher uses `$field->getStudioInlineEditedThumbnailPreset()` as `inlineImagePreset` when scheduling processing with `ThumbnailProcessor::schedule()`. Generate the requested tile rendition before presenting the accepted result if the form needs it immediately. Other variants follow the configured processing pipeline.

## Configure cleanup in PHP

```php
$field->deleteReplacedFiles(true);
```

The classic callback receives this as `$deletePreviousVersion`. The AI acceptance callback reads the same field policy. Commit and verify the replacement before retiring the previous version. Referenced versions or temporarily failing storage can leave cleanup pending for scheduled maintenance. [Previous-version retention](/filament-flex-file-upload#keep-only-the-current-edited-image)

## Verify each acceptance path

Edit one image in a field containing several files. Cancel once and confirm the original references remain. Then save or accept one result and reload the form. The edited image should resolve its new version, the other selections should remain, and the requested thumbnail should be available or show its processing fallback. Verify that a stale source revision is rejected before publishing.
