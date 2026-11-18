---
title: Queues and scheduled maintenance
description: Route media jobs to workers, review the scheduler and verify recovery of interrupted work.
---

A queue worker processes dispatched jobs. The scheduler starts periodic maintenance, including recovery and cleanup. Run both services when your configuration uses both.

## Match workers to enabled features

| Feature | Where to set or verify the queue |
| --- | --- |
| Native images and video processing | [Processing configuration](/processing-configuration) |
| Document thumbnails | [Document thumbnails](/document-thumbnails), when execution is set to queue |
| Audio waveform generation | [Audio previews and waveforms](/audio-previews) |
| Assistant | [Assistant configuration](/assistant), with an asynchronous connection |
| Background ZIPs | [Archives and bulk downloads](/archive-downloads#prepare-an-archive-in-the-background) |
| Permanent deletion | [Trash and retention](/trash) |
| Workspace transfer and removal | [Workspace removal](/workspace-retirement) |

For example, if the configured connections are Redis and the assistant and archive queues are named `media-studio-ai` and `media-archives`, run a supervised process for each.

```bash
php artisan queue:work redis --queue=media-studio-ai
php artisan queue:work redis --queue=media-archives
```

Set job, worker and retry timeouts for the corresponding feature. Use the [archive guide](/archive-downloads#prepare-an-archive-in-the-background) for the longer archive job timeout. Ensure each worker can read and write its configured disks and run any processor binary it needs. Restart long-running workers after deploying updated code or configuration.

## Verify scheduled tasks

```bash
php artisan schedule:list
php artisan queue:failed
php artisan list media-studio
```

The provider registers maintenance for navigation, workspace retirement and edited versions. Trash, Slack, API housekeeping, notifications and assistant maintenance depend on feature settings. The actual list for your installed app is authoritative. Add the schedules documented for [upload receipts and staging](/upload-recovery) and [prepared archives](/archive-downloads#prepare-an-archive-in-the-background) if you enable those features. Coordinate scheduling across app replicas using shared cache locks or a single designated scheduler.

## Test an interrupted workflow

In a test workspace, submit an image and an archive to their configured workers. Check that the uploaded original becomes usable independently of the thumbnail and that the ZIP becomes available only after its job finishes. Test recovery using a disposable transfer in the [upload recovery guide](/upload-recovery). If an item stays pending, inspect the connection, queue name and failed jobs before retrying.
