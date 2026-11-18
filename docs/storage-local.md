---
title: Private local storage
description: Configure local managed storage, shared volumes and a practical backup and restore test.
---

Start with a disk outside the document root. Studio delivers media through authorized endpoints and stores the selected disk and root per workspace.

## Give Studio its own disk

Add this disk under `disks` in `config/filesystems.php`.

```php
'studio-local' => [
    'driver' => 'local',
    'root' => storage_path('app/private/studio-files'),
    'visibility' => 'private',
    'throw' => true,
],
```

Select it in `config/media-studio.php` before creating a workspace.

```php
'storage' => [
    'disk' => 'studio-local',
    'root' => 'media-studio',
],
```

Give web and worker processes the necessary filesystem permissions. When running multiple application instances, mount the same persistent storage on every process that handles originals or derivatives. If [resumable uploads](/upload-resumable) are enabled, those requests also need shared access to the session staging directory. Budget that space separately.

## Verify permissions and recoverability

Upload an image, generate its thumbnail and download the original as a permitted user. Confirm the physical path is outside your public document root. In a recovery environment, restore a matching database snapshot and the workspace files. Check the original, thumbnail and any required encryption keys together. On hosts with ephemeral local filesystems, use a persistent volume or [private S3](/storage-s3).
