---
title: "Shared upload options"
description: "Configure Studio-backed native and FLEX Fields uploads."
---

Both upload adapters support the same Studio configuration. Call `studio()`
with a server-resolved workspace ULID (or closure), the module URL, and optionally
the stylesheet URL, `uploadFolder`, `acceptedMimeTypes`,
`allowLibrarySelection` and `generateRenditions`. The default destination is the configured scope root, or the workspace root
when no scope is set. Direct upload, library selection and rendition processing
are enabled initially. The native adapter publishes on form save, while the
FLEX adapter publishes immediately by default.
| Configuration | Effect |
| --- | --- |
| studioUpload(bool or Closure) | Permit new uploads. FLEX hides its file button when disabled. |
| studioLibrarySelection(bool or Closure) | Show and authorize selection from the library. |
| studioUploadFolder(string, Closure or null) | Set the destination Studio folder. |
| studioBrowseFolder(string, Closure or null) | Choose the initially displayed folder. |
| studioScopedFolder(string, Closure or null) | Restrict selection and upload destinations to the folder's subtree. |
| studioGenerateRenditions(bool or Closure) | Enable Studio's configured rendition processing. |
| studioThumbnailPreset(string, Closure or null) | Use a named ready image rendition for picker and selected thumbnails, or null for the default. |
| persistStudioUploadsImmediately(bool or Closure) | Publish immediately or when the form saves. |
| configureStudioPickerUsing(Closure) | Customize the shared picker and serializable React presentation. |
| acceptedFileTypes(), minSize(), maxSize() | Native Filament file validation. |
| multiple(), minFiles(), maxFiles() | Selection and upload cardinality. |
| downloadable(), openable(), reorderable(), deletable() | Selected-file controls. |
| disabled(), required(), validationMessages() | Native form state and validation. |
| uploadLayout(), gridColumns(), gridTileSize(), fileUploadSourceLabel() | FLEX presentation. |

Workspace and folder closures use current field context and Filament utility
injection. Supply application-owned workspace IDs for the authenticated tenant.
Studio rechecks workspace access on selection, validation, metadata resolution
and publication. Folder checks use the current server hierarchy.

Configure S3-compatible disks, encryption and renditions in Studio's storage and
processing settings. FileUpload's native disk() and directory() configure its
native transport. Studio folder IDs determine the logical Studio destination.
Existing Spatie collections can be exposed through the optional Studio Spatie
source adapter, retaining their existing storage/conversion ownership.

FLEX assets use its existing per-instance asset manager. Studio's React module
and stylesheet load when the picker modal mounts. Closing the modal disposes its
React instance. Each instance retains its own scope, state and lifecycle.

Register [media usages](/configuration#store-references-in-your-own-application)
when saving the application record. Removing a form reference keeps the workspace
asset under Studio's normal ownership and retention policy.


## Choose a thumbnail per record

```php
StudioFileUpload::make('assets')
    ->studio(
        workspace: fn (Campaign $record): string => $record->studio_workspace_id,
        moduleUrl: $moduleUrl,
        stylesheetUrl: $stylesheetUrl,
    )
    ->studioThumbnailPreset(
        fn (Campaign $record): string => $record->thumbnail_preset,
    )
    ->multiple()
    ->maxFiles(8);
```

This example uses a persisted Campaign. For create forms, resolve the workspace
from authenticated context so the field can render before the record exists.
See [media references](/filament-media-references) for deletion and validation.
