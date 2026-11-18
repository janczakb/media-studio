---
title: Workspace settings and removal
---

Owners with `workspaces.manage` can rename a workspace, make it the default, and
remove a non-default workspace from its account-menu settings. Choose another
default first when removing the current default. The multiple-workspace feature
must be enabled; its config still hides all switch/create/removal controls when off.

Removal is a durable operation, not a cascading HTTP deletion. The confirmation
step requires the exact workspace name and acknowledgement. Choose:

- **Move files:** select another owned workspace in the same tenant. Contents are
  placed into a dedicated folder, preserving entry/version identity, hierarchy,
  comments and supported indexed metadata. Managed object bytes are streamed into
  the target namespace and checksums verified before database publication. Target
  permissions apply after transfer. Old links are revoked on admission and removed
  with the source workspace; public access is never silently carried to a new ACL.
- **Delete files:** Studio-owned originals/renditions are deleted using durable
  object receipts. Entry metadata, comments, links and workspace-related rows are
  removed. Host-owned external originals are retained. Studio usage records are
  removed; arbitrary host application records/HTML referencing files are not edited.

Source writes are frozen during the operation. Active uploads/processors/assistant
runs can prevent admission; finish/stop them first. Progress survives a reload and
is available when reopening settings. A failure retains its checkpoint; resolve
permissions/storage and retry. A completed operation cannot be undone. Closing the
modal does not cancel the background job.

Apply core migration `2026_11_11_000053_workspace_retirement.php` and run Laravel's
scheduler. Use an asynchronous queue in production:

```php
'workspaces' => [
    'enabled' => true,
    'max_owned' => 10,
    'operations' => ['connection' => 'database', 'queue' => 'media-studio-workspaces'],
],
```

Run a worker on that queue with a timeout longer than the job's 120-second timeout
and a retry-after longer than the worker timeout. The maintenance command retries
lost dispatches in bounded batches. A `sync` host connection is never used for
large deletion work inside the HTTP request; scheduler maintenance advances it
in bounded passes instead. For substantial libraries configure a real queue.

API: `POST workspaces/{workspace}/retirement` accepts `confirmName`, `mode`
(`move`/`delete`) and a target workspace ULID for moves. It returns 202 with an
operation ID. `GET workspace-operations/{id}` returns state/phase/processed count;
`POST workspace-operations/{id}/retry` resumes a failed operation after reauthorization.
React transport exposes `retireWorkspace`, `workspaceOperation`, and
`retryWorkspaceOperation`. The older DELETE endpoint remains empty-workspace-only
for compatibility; the redesigned UI uses the resumable operation endpoint.

Bounds: five entries per worker step, maximum 1000 records per entry per object
source table, 20 old objects per cleanup pass, ten recovery admissions per minute.
Files are streamed, not materialized into PHP memory. Verification adds storage
reads; there is no universal throughput or million-file benchmark claim. Imported
source mount/name conflicts can stop transfer rather than overwrite target data.
