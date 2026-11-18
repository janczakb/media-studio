---
title: "Archives and bulk downloads"
description: "Configure streamed ZIPs and private archives created by workers."
---

Test a small selection first, then a folder with mixed content. For public collections, check the share’s separate download and archive controls.

## Stream a ZIP archive

Enable **Allow downloading the full collection** to let visitors download the shared collection as a ZIP archive, including folders and their contents. This option requires **Allow downloads** and is disabled for new links until selected. Existing links that allowed downloads before this option was added retain permission to download the full collection. Studio users can download a folder or their selected files and folders from the library. The archive contains the current accessible originals.

When browsing a shared folder, visitors can download just that folder and its contents. The server verifies that the folder is still part of the shared collection.

Archives are streamed without storing a complete copy on the server. The default maximum is 10,000 entries, counting folders as well as files; set `downloads.max_archive_entries` in `config/media-studio.php` to change it (maximum 50,000). Large archives need a server and proxy timeout suitable for the download. If access changes or a source becomes unavailable during transfer, the download can be incomplete and should be retried. ZIP64 support is required to open very large archives.

## Prepare an archive in the background

For signed-in Studio users, the optional background mode prepares a private ZIP and shows its status in the library. Ready archives can be downloaded more than once until they expire. If the selection or access changes, prepare a new archive.

```php
'downloads' => [
    'max_archive_entries' => 10000,
    'background_enabled' => true,
    'background_disk' => 'local', // Use a private disk.
    'background_max_entries' => 50000,
    'background_ttl_hours' => 24,
    'connection' => 'redis',
    'queue' => 'media-archives',
],
```

Run a queue worker on the configured connection and queue. Schedule `php artisan media-studio:recover-archives` and `php artisan media-studio:prune-archives` periodically. Recovery retries jobs left waiting and work interrupted for more than 70 minutes. Configure the queue so a worker is stopped within its 60-minute job timeout and the queue retry interval is longer than that timeout. The worker uses temporary local disk space at least as large as the archive before transferring it to the configured private disk. Choose worker timeouts and disk capacity accordingly. Install the latest Studio migrations before enabling the option. Guests with permission to download an entire collection can also prepare an archive in the background; changing or revoking the share invalidates that prepared archive.
