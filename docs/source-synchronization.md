---
title: "Keep external libraries up to date"
---

Studio can periodically refresh its index of existing disk files and Spatie media. Browsing stays fast: visitors read the database index, rather than triggering a storage scan. Synchronization never deletes or rewrites the external originals.

Run the package migrations before configuring synchronization. Register the source mount first, using the workspace and principal restrictions described in [existing disk sources](/existing-disk-sources).

## Configure a target

Add named targets to `media-studio.sources.sync.targets` in your published configuration:

```php
'sync' => [
    'targets' => [
        'campaign-archive' => [
            'enabled' => true,
            'kind' => 'disk',
            'workspace' => 'YOUR_WORKSPACE_PUBLIC_ID',
            'mount' => 'campaigns',
            'principal_type' => 'user',
            'principal_id' => 'YOUR_SERVICE_USER_ID',
            'interval_seconds' => 300,
            'missing_grace_seconds' => 300,
            'discover' => true,
            'revision' => 1,
        ],
    ],
],
```

The principal must remain a workspace owner/admin and hold `studio.sources.sync` plus the normal write permissions. Mount authorization is also checked again before publishing changes. Disabling a user or removing permission stops their synchronization; it does not authorize anonymous background access.

For Spatie, use `kind => 'spatie'` and the key of a configured Spatie mount. Its scoped media query determines which records can be synchronized. Existing collections and custom media models remain supported through that mount.

## Run automatically

In Laravel's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:sources:sync --limit=60 --pages=10 --max-seconds=20')
    ->everyMinute()
    ->withoutOverlapping();
```

The application's standard Laravel scheduler must be running. A queue worker is not required. No targets are enabled by default, and installing Studio does not install an operating-system cron job.

For a single library or a manual check:

```bash
php artisan media-studio:sources:sync campaign-archive --limit=30 --pages=5
```

Each page checks at most 120 records. Progress is saved between pages; interrupted work can replay its last page safely. A database lease prevents two invocations from publishing the same target concurrently. `--max-seconds` stops admitting another page; it cannot interrupt an in-flight storage request. Set appropriate timeouts on the Laravel disk as well.

## What is synchronized

| Source | Existing files | New files |
| --- | --- | --- |
| Local or custom Laravel disk | Size and modification time; confirmed absence | Import an inventory for new paths |
| Native Laravel S3 disk | Object metadata; confirmed object absence | Bounded S3 listing when `discover` is enabled |
| Spatie mount | Scoped model changes and removed records | Cursor-paginated discovery from the scoped media table |

Disk metadata is not a content checksum: replacing bytes while preserving both size and modification time is not detected. For those systems, explicitly reimport with a changed mount revision or integrate their own change events. S3 discovery is incremental listing, not a point-in-time bucket snapshot. Missing objects in a listing alone never prove deletion.

## Removed and restored files

A missing file needs at least two successful absence observations and the configured grace period before Studio hides its index entry. Permission errors, timeouts and server errors stop the page instead of counting as absence. Spatie also rechecks the current scoped model at read time, so removed access is not delayed by synchronization.

Hiding a missing entry preserves its identity, versions and references. When the same source identity becomes available again, synchronization restores it. Empty source folders remain in the index; this process does not delete the folder hierarchy or erase original storage objects. Changed files receive a new indexed version and stale image rendition references are cleared; generate new renditions through the configured processing/backfill workflow.

Changing a disk root, bucket, endpoint, prefix or mount revision invalidates older projections. Reimport the source after such a change. Increase the target `revision` to restart its maintenance cursor after changing discovery configuration. Keep targets separate for independent workspaces and schedule high-volume targets separately to give them their own time budget.
