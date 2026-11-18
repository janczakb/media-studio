---
title: "Trash, restore and retention"
description: "Restore deleted assets, configure retention, and understand how folder deletion affects descendants."
---

Use Trash to review and restore removed entries. Workspace removal has a separate [maintenance lifecycle](/workspace-retirement).

## Deleted files

**Deleted files** shows managed files and folders in the workspace trash. Filter by item type, deletion date, or your own actions. **Restorations** shows who restored an item and when. Items can be restored while they remain in the trash. Nonempty managed folders are processed as durable operations. The selected folder disappears immediately from the library; bounded discovery validates the subtree before one atomic SQL transition. Failure restores its visibility without publishing a partial deletion. Storage originals remain intact in trash. Other writes in that workspace are temporarily fenced during preparation; reads remain available.

Trashing deletes every share/publish link directly containing an affected entry, including links to descendants. A selection link containing both removed and remaining files is deleted as a whole. Restore never revives deleted links. Shared archive downloads are invalidated; object receipts remain for asynchronous cleanup. Private original/preview/download requests recheck the current entry state. Existing links to already-trashed entries are denied immediately and removed by bounded maintenance.

Permanent deletion is opt-in. To keep deleted items for 30 days and send cleanup work to a dedicated queue, add these settings to `config/media-studio.php`.

```php
return [
    // Other Studio settings...
    'trash' => [
        'retention_enabled' => true,
        'days' => 30,
        'connection' => 'redis',
        'queue' => 'studio-maintenance',
        'max_attempts' => 12,
        'max_objects_per_entry' => 1000,
    ],
];
```

Choose a Laravel queue connection available in your application. Use a worker for the selected queue. The workspace owner identity comes from your configured Studio principal map. Replace `WORKSPACE_ULID` and `OWNER_ID` with the workspace and its owner's identifiers.

```bash
# Inspect the oldest expired items.
php artisan media-studio:trash:prune WORKSPACE_ULID user OWNER_ID --limit=50

# Queue up to 50 cleanup operations.
php artisan media-studio:trash:prune WORKSPACE_ULID user OWNER_ID --limit=50 --apply
```

Studio registers `media-studio:trash:maintain` every minute when `trash.schedule` is true (default). Run Laravel's scheduler on your hosting. A queue worker is optional when the configured connection is `sync`; a dedicated asynchronous queue is recommended for remote storage. The maintenance tick resumes interrupted folder operations and, **only when `retention_enabled=true`**, admits expired items in bounded batches, children before parents. Therefore `days` is the earliest cleanup eligibility, not a guaranteed exact deletion deadline. With retention disabled (the default), trash remains until explicitly handled. To run the same bounded tick manually:

```bash
php artisan media-studio:trash:maintain
```

`trash.tree_batch_size` controls discovery work per step (default 200, bounded 10–500). The browser actively advances its operation; closing the browser leaves durable progress for maintenance or explicit resumption. To disable automatic scheduling and use your own schedule, set `trash.schedule=false`.

Studio checks the current workspace and owner, original version, and attached usages before deleting managed storage objects. A file can no longer be restored once cleanup is queued. Once any member of a trash group starts permanent cleanup, the whole group becomes non-restorable, preventing partially restored folders. If cleanup stops partway, its progress is saved and the worker can finish the remaining objects. The retention job leaves Spatie and other provider-owned objects under their provider's control. Superseded native conversions continue to follow the retention policy selected when they were replaced.

When automatic retries are exhausted, a current owner can retry an unfinished operation using its purge ID from `fms_trash_purges`.

```bash
php artisan media-studio:trash:prune WORKSPACE_ULID user OWNER_ID --retry=PURGE_ULID --apply
```

## Folder operation API

Single-entry trash/restore and bulk trash/restore return `202` with `{treeOperation, items:[]}` when a folder or a grouped trash item is selected. The operation is durable before that response. Ordinary single files keep the synchronous `200 Entry` response.

- `GET /workspaces/{workspace}/tree-operations/{operation}` reads progress.
- `POST /workspaces/{workspace}/tree-operations/{operation}/advance` performs one bounded step.
- `POST /workspaces/{workspace}/tree-operations/{operation}/cancel` cancels before commit.

Terminal states are `completed`, `failed`, and `cancelled`. `discovered` and `examined` are actual counts, not a percentage estimate. Each request rechecks workspace, tenant, principal and current permissions. The initiating principal or an authorized owner/admin can manage the operation. `requestId` (16–64 characters) is the admission idempotency key; reuse it only with the same operation and item revisions.

`createHttpTransport` and `createStudioApi` await completion and preserve the existing `trash`/`restore` return values. Both expose `treeOperations.status/advance/cancel`; HTTP transport accepts an optional `onTreeOperation` progress callback. Closing an HTTP wait does not cancel work. Use the explicit cancel API for that. Large subtree discovery does not require a dedicated worker, but recovery after closing the browser requires the Laravel scheduler or an explicit advance call.
