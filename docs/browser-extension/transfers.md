---
title: "Transfers, retries and recovery"
description: "Understand the persistent queue, upload completion, local capacity and failure handling."
---

After a batch is saved, open **Transfers** in the manager to see its jobs. The queue records source download, upload and metadata publication separately. Your browser profile keeps the jobs locally so closing a manager tab does not erase the recorded queue.

## Understand completion

| State | Meaning |
| --- | --- |
| queued | Waiting for an available transfer slot or retry time |
| downloading | Fetching the selected source into local extension storage |
| uploading | Sending the file to the approved Studio workspace |
| paused | Waiting for Resume |
| error | Requires correction or an explicit retry |
| done | File and capture metadata have been accepted |
| cancelled | Cancelled locally, with server-session cleanup attempted when applicable |

The progress bar can show all upload bytes sent while metadata is still being saved. Studio thumbnail processing can finish after the transfer is done. For example, a tag-permission failure after upload leaves the file in Studio and the queue records the metadata error. Retry continues the existing job's metadata step.

## Pause, resume and cancel

Use a job's **Pause**, **Resume**, **Retry** or **Cancel** control. Queue-level Pause and Resume apply to unfinished jobs. **Clear finished** removes completed and cancelled local history and its retained job blobs. It preserves accepted Studio assets.

Cancellation aborts the active local request and attempts to cancel a recorded server upload session. A file already accepted by Studio can remain there. Inspect the destination and use Studio's [trash workflow](/trash) if you also want to remove that asset.

## Ordinary and resumable uploads

Studio publishes small files through the ordinary upload endpoint. When resumable sessions are enabled and the file exceeds the selected threshold, the extension records a session and uploads parts through the application. A resumed session reads accepted parts and skips those already stored.

The extension requests `direct: false`. It can save to a workspace backed by S3 while the transfer passes through Laravel. Enabling direct S3 on the host does not switch the current extension queue to signed S3 part PUTs. [Server upload configuration](/upload-resumable)

The job retains its upload key and accepted entry identity. Retry the existing job for an interrupted transfer. Starting a new capture creates new queue jobs and new operation keys.

## Automatic recovery and limits

Network errors, rate limits and server failures can be retried automatically. The queue uses an increasing delay and stops automatic retrying after four failed attempts. Manual Retry resets the attempt counter. Authorization and validation responses generally need a correction before retrying.

A background alarm checks the queue, and stale active jobs can be returned to queued after interruption. Progress depends on the browser running and allowing extension background work. If a recorded session expires before resumption, cancel that job and recollect the file to start a new session.

| Resource | Current bound |
| --- | --- |
| Selected assets per save | 300 |
| Stored queue job records | 500, including finished history until cleared |
| Concurrent transfers | At most 3, configurable |
| Retained local blobs | 256 MiB |
| Maximum individual source download | Configurable up to 128 MiB, further bounded by the server policy |

Queue admission also applies a 256 MiB budget based on job sizes already known at admission. Newly discovered remote sizes can require capacity checks later when bytes are stored. Finish jobs and clear completed history before starting another large batch.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Website access was not granted | Grant the actual source host, including its CDN when applicable |
| Source returns a login page or HTML | Try an authorized direct file URL or review the same-origin browser-session option |
| Source returns 401 or 403 | Check source-site access and whether its file URL has expired |
| A redirect is rejected | Collect a reachable direct asset URL, since downloads reject automatic source redirects |
| Upload returns 401 | Reconnect an expired or revoked Studio connection |
| Upload returns 403 | Check current workspace, extension and upload permissions |
| Tags fail after the file appears | Check `studio.tags.manage`, then retry the existing job |
| File exceeds a limit | Compare extension maximum, Studio MIME/size policy and resumable settings |
| Queue or local capture storage is full | Finish uploads, cancel unwanted pending items and clear finished history |
| Collection expired | Return to the source and collect it again |
| Some files saved and others failed | Inspect each job. Successful jobs remain accepted |
| Preview stays pending in Studio | Check the configured processing worker independently of transfer completion |

Browser storage can also be removed by clearing extension data or uninstalling the extension. Verify that required files are present in Studio before removing that local state.
