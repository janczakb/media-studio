---
title: Deploy and operate Studio
description: Configure queues, scheduled maintenance, private storage, and smoke tests for a Laravel installation.
---

Choose a [private local disk](/storage-local) or [S3 disk](/storage-s3), then configure [queues and scheduled maintenance](/queues-and-scheduler) for your enabled features.

## Run only the workers you configure

Use Laravel's normal scheduler and queue worker service. Route each optional worker to its configured connection and queue. Keep the timeout and retry settings consistent with the longest supported job in that queue.

```bash
php artisan schedule:list
php artisan queue:failed
php artisan queue:work redis --queue=media-studio-ai
php artisan queue:work redis --queue=document-thumbnails
```

These worker examples assume a configured Redis connection and matching feature settings. For video edits, thumbnails, assistant runs, and workspace operations, use the actual queue names in the published configuration. Check the Laravel worker process after deploying configuration or code changes.

## Validate delivery and recovery

1. Test a signed-in user and an unauthorized user against the same workspace and asset.
2. Upload a small image and an allowed large file. Verify the original, progress, and derivative states independently.
3. Preview a document and seek within a video through the same ingress and CDN used in production.
4. Create and disable a test share. Reopen its URL in a separate session to verify revocation.
5. Verify a restore from the application's backup process with the database, private objects and required encryption keys in the same recovery exercise.

| Symptom | First place to inspect |
| --- | --- |
| Workspace or extension route returns 404 | Feature configuration, actual route prefix, optional migrations and config cache |
| Upload works, thumbnails stay pending | Queue and processor binaries on the worker machine |
| Signed media fails after a host change | [Delivery profile and URL signing](/cdn-delivery) |
| Missing files from a read-only mount | [Source reconciliation](/source-synchronization) |
| API client can authenticate but receives a permission error | [Token scopes and workspace permissions](/api/authentication) |

```bash
php artisan route:list --path=media-studio
php artisan media-studio:navigation:maintain --help
php artisan media-studio:trash:maintain --help
```

See [encryption](/encryption) for key handling and existing-object migration. Keep Studio environment values, API tokens and provider credentials out of public logs and browser bundles.
