---
title: "Browse existing files without moving them"
---

Connect an existing Laravel disk to a workspace when you want to browse its files without uploading them again. Studio creates a read-only index, preserves folders and serves files through its authenticated routes. The original objects stay on their disk. Removing the source configuration hides the indexed files; it does not delete the originals.

This workflow supports local storage and Laravel S3 disks. The initial inventory/import is explicit, and browsing never scans storage. Enable [scheduled synchronization](/source-synchronization) to maintain indexed changes and confirmed removals automatically.

## Register a source

Define the source in a service provider. Always scope access to the intended workspace and principal. Do not use an unconditional allow callback in a multi-tenant application.

```php
use UseFlex\MediaStudio\Identity\Principal;

config()->set('media-studio.sources.mounts.campaigns', [
    'enabled' => true,
    'label' => 'Campaign archive',
    'disk' => 's3',
    'prefix' => 'company-42/campaigns',
    'revision' => 1,
    'authorize' => static function (object $workspace, Principal $principal): bool {
        return $workspace->public_id === config('app.campaign_workspace')
            && $principal->type === 'user'
            && in_array($principal->id, config('app.campaign_members', []), true);
    },
]);
```

Workspace membership and Studio's Spatie permissions are still required. Import is restricted to workspace owners/admins with write permission and `studio.sources.sync`. The source callback adds a further restriction; it cannot bypass workspace authorization. Register callbacks in a provider rather than a cached PHP configuration file.

Install the latest Studio migrations before using the commands.

## Create an inventory

```bash
php artisan media-studio:sources:inventory WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl
```

Choose a local path outside your public web directory. The output is created with owner-only permissions. An existing file is never overwritten. Listing streams through Flysystem without materializing the entire directory or bucket. Source objects are not downloaded or copied. For local disks, a failed inventory is removed and can be generated again. S3 disks support the resumable workflow below.

### Resume a large S3 inventory

S3 listing saves progress after each page of up to 1,000 objects. By default, one command handles at most 100 pages. Choose a smaller batch when running scheduled commands:

```bash
php artisan media-studio:sources:inventory WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl --pages=10
```

If it reports a saved checkpoint, continue with the same arguments and `--resume` until it reports **Inventory ready**:

```bash
php artisan media-studio:sources:inventory WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl --pages=10 --resume
```

The final JSONL appears only when listing is complete. Keep its private `.partial`, `.checkpoint` and `.lock` sidecar files in place while scanning. An interrupted page is discarded on resume and fetched again. Completed pages are not listed again. Only one process can advance a given output at a time, and authorization is checked before and after every storage page. No worker is required; a scheduler may run the command repeatedly. `--pages` accepts 1–1,000.

The encrypted checkpoint belongs to the initiating workspace, principal, mount revision and storage configuration. Use the same Laravel encryption key when resuming. If the source configuration changes or the provider expires its continuation token, choose a new output and create a fresh inventory. An S3 listing is not a frozen snapshot of a changing bucket: schedule another inventory to reconcile later additions. This workflow does not infer deletions.

You can also supply your own UTF-8 JSONL inventory, with one object path per line:

```text
{"path":"company-42/campaigns/summer/banner.avif"}
{"path":"company-42/campaigns/summer/brief.pdf"}
```

Paths are relative to the configured Laravel disk root and must stay inside the source prefix. Records are limited to 8192 bytes and object paths to 1024 bytes. The import preserves up to 32 nested directories. Empty directories are not imported.

## Inspect before importing

```bash
php artisan media-studio:sources:import WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl --limit=60
```

Inspection validates access and object metadata without writing library entries. Each batch contains at most 120 records. Only import reads object metadata; ordinary library browsing reads the database index.

## Import and resume

```bash
php artisan media-studio:sources:import WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl --limit=60 --apply
```

The command returns JSON containing `inspected`, `imported` and `nextCursor`. Repeat with the returned cursor until it is `null`:

```bash
php artisan media-studio:sources:import WORKSPACE campaigns user USER_ID /private/path/campaigns.jsonl --limit=60 --apply --cursor='RETURNED_CURSOR'
```

Continuations expire after seven days and are bound to the inventory identity, workspace, principal, source configuration and inspection/import mode. Preserve the immutable inventory while importing. A cursor seeks directly to the next byte position; it does not reread all preceding lines or hash the full inventory on every batch. Treat this as an administrator-owned local file, not an untrusted upload. Do not rewrite it in place while an import is running.

Retrying the same batch preserves asset IDs and does not duplicate unchanged versions. A failed batch rolls back its index changes. Source objects are never removed by this importer. Files absent from an inventory are **not** automatically considered deleted.

## Manage imports in Settings

After preparing an inventory, register its private local path on the source in your provider:

```php
config()->set(
    'media-studio.sources.mounts.campaigns.inventory',
    storage_path('app/private/campaigns.jsonl'),
);
```

Open **Settings → Existing sources** as an authorized workspace administrator. Start the import, pause it, continue it or cancel further processing. Studio processes sequential batches of up to 60 inventory records. Progress is stored in the database, so refreshing the page preserves the last committed position.

Keep this settings section open while importing. Closing it stops new batch requests; an already committed batch remains imported. Continue the run after reopening Settings. This mode does not require a queue worker and does not run unattended after the browser closes. The initiating administrator can continue a run; another authorized administrator can pause or cancel it.

Pausing or cancelling invalidates an active batch before it can publish further changes. It does not remove files already indexed by previous batches. A changed inventory or source configuration requires review before continuing; preserve the inventory unchanged throughout the run. The screen shows the latest 30 runs. Inventory paths remain server-side and are not exposed to the browser.

## Browsing and access

The source appears as a read-only root folder. Folder navigation, search/filtering, selection and content delivery use the existing Studio interface. Files without a registered thumbnail show a type icon; opening the file can use its original. Import itself does not generate derivatives or copy source thumbnails. You can explicitly enable and run image conversion as described below.

Source access is checked again when resolving indexed items and opening originals. Disabling a mount, changing its disk/prefix/revision or denying its authorization callback blocks the affected source. Reimport after a source configuration change.

Before serving an original, Studio compares its size and modification timestamp with the index. Changed or missing objects are unavailable until reimported. This is not a cryptographic content check: an external system that overwrites the same path with the same size and timestamp needs immutable object keys or a future version-aware adapter. Historical indexed versions of mutable disk paths cannot be opened as preserved snapshots.

## Generate thumbnails for imported images

Enable Studio-owned image conversions separately for each trusted source:

```php
config()->set('media-studio.sources.mounts.campaigns.renditions', true);
```

This option is off by default. It supports imported JPEG, PNG, WebP and AVIF images within the configured processing limits. It uses Studio's named conversion profiles, encoder selection, quality settings and queue connection. Existing Spatie conversions remain managed by Spatie.

Inspect a bounded batch first:

```bash
php artisan media-studio:renditions:backfill WORKSPACE user USER_ID --source=disk --limit=100
```

Then generate conversions:

```bash
php artisan media-studio:renditions:backfill WORKSPACE user USER_ID --source=disk --limit=100 --apply
```

Continue with `--after=NEXT_CURSOR` printed by the command. Non-image entries, inaccessible sources and sources without conversion opt-in are skipped. A queue connection requires its worker; the `sync` connection performs processing during the explicit command instead. Browsing the library never starts conversions. The initiating principal needs the relevant `studio.files.optimize` and `studio.files.process` permissions as well as source access.

By default, generated variants are written to the workspace's Studio disk. A named profile may explicitly select another output disk. Originals stay at their external paths and are never removed by Studio's original-retention setting. Refresh variants using `--refresh --retention=keep` or `--refresh --retention=prune`; pruning applies to replaced Studio-owned derivatives, not source originals. See [Image conversions](/image-conversions) for profiles and cleanup.

The worker checks the indexed source before processing and again before publishing variants. If the file changes, reimport the source before regenerating. These checks compare size and modification time; they do not make mutable external paths into cryptographically immutable snapshots. Revoking source access also blocks delivery of generated variants.

## Current scope

This release adds explicit inventory/import and read-only indexed browsing. Automatic source event synchronization, incremental inventory generation, deletion reconciliation, source writeback and unattended scheduling remain separate work. Live S3 behavior requires testing against your configured endpoint and permissions; local filesystem tests do not prove a production S3 integration.
