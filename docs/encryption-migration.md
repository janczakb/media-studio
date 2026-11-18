---
title: "Encryption migration and diagnostics"
description: "Plan and verify the re-encryption of Studio-owned media."
---

Use the diagnostics and a staged migration to verify actual coverage before applying a policy to existing managed files.

## Resumable migration of owned files

Configure a dedicated application-encrypted or native SSE target disk. Drain
uploads/conversion/archive workers, then run:

```sh
php artisan media-studio:encryption:files --workspace=WORKSPACE_ULID --target=ENCRYPTED_DISK --batch=1
php artisan media-studio:encryption:files --resume=OPERATION_ULID --batch=1
```

The operation migrates indexed managed originals/versions, ready owned renditions,
managed preview/thumbnail pointers and indexed archives. External Spatie/application
source files remain external. No whole bucket/filesystem scan is performed.
Workspace writes are locked during migration; reads remain available. Each object
is copied to a new key, checked against original catalogue digests where available,
then fully decrypted and verified before publishing catalogue pointers. Pointer and
checkpoint updates commit together. Retries reuse verified copies; fresh writer
attempts use distinct keys to prevent stale writers overwriting current copies.
A batch bounds object count, not the duration of one large copy: choose a suitable
CLI/worker timeout.

Failure keeps the workspace write lock and checkpoint. Fix the storage/key/digest
problem and resume the same operation. There is currently no generic rollback or
unlock action; keep both disks and complete the operation.

Recovery originals and abandoned attempts are deliberately retained. Completion
means active pointers have moved, not that historical plaintext has been erased.
Source cleanup is a separate retention/reference-aware step not yet implemented.
Do not remove recovery sources or old reader keys prematurely, and do not claim
full at-rest coverage while plaintext recovery copies/backups remain.

## Coverage diagnostics

```sh
php artisan media-studio:encryption:check --json
```

The bounded report distinguishes configured protection, migration state and retained
recovery objects. `fullProtectionVerified` remains false: independent audit,
metadata/index/cache/queue/backup and host temporary-storage boundaries cannot be
certified by a PHP checkbox. No “Fully encrypted” badge is enabled from configuration.
