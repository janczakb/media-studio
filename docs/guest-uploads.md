---
title: Accept uploads from guests
description: Collect files through a protected share link and set per-link limits.
---

Use a dedicated managed folder to collect assets from a customer or contractor. Create a password-protected share with an expiry, allow guest uploads, and leave original downloads disabled if the guest only needs to submit files.

## Let guests upload files

Guest uploads are optional and disabled by default. Enable them globally, then turn on **Allow guests to upload files** when sharing a single managed folder:

```php
'sharing' => [
    // Keep your other sharing settings.
    'guest_upload_enabled' => true,
    'guest_upload_max_kilobytes' => 25600,
    'guest_upload_max_total_bytes' => 1073741824,
    'guest_upload_max_files' => 1000,
],
```

Guests can upload into that folder or its shared subfolders without an account. The file must meet the workspace's allowed extensions and MIME checks, and the smaller of the guest and normal upload size limits applies. Total bytes and file count are capped per link; these lifetime counters do not decrease when files are deleted. Passwords, expiry, revocation, creator permissions and tenant access are checked again before the file is published. Uploads are recorded as guest activity associated with the link.

The link creator receives an in-app notification when a guest uploads a file. The current guest form uploads one file per request; it does not yet support resumable chunks or folder uploads. Rate limiting should use a shared cache such as Redis when running multiple application instances.

## Verify a submission

1. Open the link in a signed-out browser and unlock it with the share password.
2. Submit an allowed file and check that it appears in the destination folder with guest activity.
3. Confirm the link creator receives the upload notification.
4. Revoke the link and verify the signed-out browser can no longer submit files.

If an upload fails, check both upload size limits, the MIME and extension rules, the remaining link quota, and the creator’s current upload permission. See [Manage links](/sharing#manage-links) for permission and revocation rules.
