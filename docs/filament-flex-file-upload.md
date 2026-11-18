---
title: "FLEX Fields FileUpload"
description: "Connect FLEX Fields\u2019 upload and library controls to Media Studio."
---

## Optional FLEX Fields FileUpload

The standard StudioMediaPicker and StudioFileUpload work with native Filament.
Install FLEX Fields only when choosing its presentation. The optional adapter
lives in Media Studio under Integrations\FlexFields.

~~~php
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;

FlexFileUpload::mediaStudio('gallery')
    ->label('Studio upload · FLEX Fields')
    ->studio(
        workspace: fn () => $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        uploadFolder: fn () => $destinationFolderId,
        acceptedMimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        generateRenditions: true,
    )
    ->multiple()
    ->maxFiles(8)
    ->maxSize(10 * 1024)
    ->maxParallelUploads(2)
    ->uploadLayout('grid')
    ->reorderable()
    ->downloadable()
    ->studioBrowseFolder(fn () => $destinationFolderId)
    ->studioScopedFolder(fn () => $allowedRootId)
    ->configureStudioPickerUsing(
        fn (StudioPicker $picker) => $picker->studioOptions([
            'previewMenu' => false,
            'previewTitleEditable' => false,
            'documentPreview' => ['print' => false],
        ]),
    );
~~~

The source card shows **Upload file** followed by **Choose media**. Choosing an existing
asset stores its versioned reference. Uploading passes through Studio's upload
service, using the workspace's configured storage, encryption and renditions.
The FLEX adapter publishes validated uploads immediately. Use
persistStudioUploadsImmediately(false) to wait for form submission.


See the dedicated [FLEX Fields integration](/flex-fields) for setup, folder and MIME constraints, persistence and diagnostics.

See the [FLEX Fields setup guide](/flex-fields/setup) and [complete field configuration](/flex-fields/configuration) for folder scopes, file counts, source buttons and layout controls.

## Configure thumbnails after an image edit

On a configured Studio FileUpload field, select the card preset and decide
whether it should be generated before the classic or AI edit finishes saving:

```php
$field
    ->studioGenerateRenditions(true)
    ->studioThumbnailPreset('thumbnail-v1')
    ->studioInlineEditedThumbnail(true);
```

Define the preset's dimensions, format and quality in
`media-studio.processing.renditions`. Use `studioInlineEditedThumbnail(false)`
to send all conversions to the background. The remaining conversions follow
the installation's configured queue or image reconciliation command.
`studioGenerateRenditions(false)` disables generation for this field.

The demo applies these settings to both editors. Custom version publishers
pass `$field->getStudioInlineEditedThumbnailPreset()` as `inlineImagePreset`
to `ThumbnailProcessor::schedule()` when
`$field->shouldGenerateStudioRenditions()` is true.

## Keep only the current edited image

The Studio demo uses one publisher for the classic and AI image editors. Both
retain earlier versions by default. The field's PHP policy controls automatic
deletion after commit, with no move to Trash.

For a field configured to remove superseded files after each accepted edit,
add `->deleteReplacedFiles()` to its existing PHP definition. It also accepts a
boolean or closure. The classic `studioImageEditor()` callback receives the
evaluated boolean as `$deletePreviousVersion`. When configuring an AI editor
publisher, read the same policy with `$field->shouldDeleteReplacedFiles()` in
the acceptance callback. The demo uses both integrations and can enable this
policy with `MEDIA_STUDIO_UPLOAD_DEMO_DELETE_REPLACED_FILES=true`.

To remove superseded files by default after successful edits, set this on the
Studio installation:

```dotenv
FMS_EDITS_KEEP_HISTORY=false
```

Install the Media Studio migrations and run Laravel's scheduler. The
`media-studio:edited-versions:maintain` command runs each minute and may
also be run manually. It checks that the replacement exists, preserves
registered version references and image comment anchors, and retries storage
failures. It applies to future edits and does not sweep existing history.

Use current-version links for consumers that should always display the latest
image. An unregistered URL pinned to a deleted version stops working at the
origin. Versioned S3 buckets require a lifecycle policy to expire noncurrent
object versions and reclaim their storage.

Custom version publishers can call
`EditedVersionRetention::retire($workspace, $entry, $previous)` in the same
workspace-locked transaction that switches the current version. The replacement
object must already have been stored. For an explicit per-field policy,
pass `manual: true` and call
`removeNow($previous->id)` after the transaction commits. A false result means
that storage deletion is pending and the scheduled worker will retry.

For disk-backed FLEX FileUpload without Studio, `->deleteReplacedFiles()`
removes the prior owned file and its registered variants after the record
accepts the new file. Use `completeMediaUploadsAfterSave($record)` with a
custom model save. Plain FLEX FileUpload does not automatically clean up
replaced files by default.

## Require review before publishing uploads

```php
FlexFileUpload::mediaStudio('photos')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: $moduleUrl,
        stylesheetUrl: $stylesheetUrl,
        uploadFolder: $incomingFolderId,
        acceptedMimeTypes: ['image/*'],
    )
    ->persistStudioUploadsImmediately(false)
    ->studioThumbnailPreset('picker-card')
    ->uploadLayout('grid')
    ->gridColumns(3)
    ->gridTileSize(96)
    ->fileUploadSourceLabel('Upload photos')
    ->multiple()
    ->minFiles(1)
    ->maxFiles(8);
```

Enable either source independently with `studioUpload()` and
`studioLibrarySelection()`. FLEX layout, grid sizing, source label, reordering
and selected-file actions are configured through the field's FLEX options.
FLEX assets use its per-instance asset manager. The Studio module and stylesheet
are loaded for the picker when its modal mounts.

The factory requires an installed FLEX Fields version exposing
`FlexFileUpload::mediaStudio()` and the Studio Laravel package. The adapter
class lives in `UseFlex\MediaStudio\Integrations\FlexFields`.
See [shared upload options](/filament-upload-options) for the full Studio API.
