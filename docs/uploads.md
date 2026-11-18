---
title: "Upload files and folders"
---

Media Studio shows progress for each file in the transfer tray. Add more files
while a transfer runs, pause and resume a transfer, pause or resume the entire queue, or retry a failed one. Folder
uploads recreate the selected folder hierarchy in the current workspace. A file
appears in the library after Studio accepts it. Its thumbnail can finish later
when you use a [queue worker](/configuration#image-thumbnails-and-workers).

| Requirement | Guide |
| --- | --- |
| Send ordinary files or folders from Studio | [Browser uploads](#configure-uploads-in-the-browser) |
| Resume a large upload through the web app | [Resumable uploads through Laravel](/upload-resumable) |
| Send large parts straight to the object store | [Direct S3 multipart uploads](/upload-s3-multipart) |
| Save originals on S3 using any supported upload transport | [Private S3 storage](/storage-s3) |
| Accept files from people without a Studio account | [Guest uploads](/guest-uploads) |
| Implement a client or recover unfinished transfers | [Upload API](/upload-client-api) · [Upload recovery](/upload-recovery) |

## Configure uploads in the browser

```ts
mountMediaStudio(container, {
    transport,
    upload: {
        enabled: true,
        allowFolders: true,
        showPanel: true,
        panelInitiallyExpanded: true,
        concurrency: 3,
        maxFiles: 500,
        maxRetainedCompleted: 100,
        progressIntervalMs: 100,
        chunkThresholdBytes: 8 * 1024 * 1024,
    },
});
```

The server applies its own limits even if the browser allows more. Set
`allowFolders: false` to remove folder selection from a particular instance.
The tray shows live progress for files selected in the current tab. After closing
or refreshing the page, the browser no longer has those local files. A paused
chunked transfer resumes by checking accepted parts with the server, including
S3 Multipart. A final publication already in progress is allowed to finish;
other files remain paused. An ordinary upload may restart its byte transfer,
using the same idempotency key.

## Upload in chunks through Laravel

[Continue to Resumable uploads through Laravel](/upload-resumable#upload-in-chunks-through-laravel).

## Upload directly to private S3

[Continue to Direct S3 multipart uploads](/upload-s3-multipart#upload-directly-to-private-s3).

## Schedule staging cleanup

[Continue to Upload recovery and cleanup](/upload-recovery#schedule-staging-cleanup).

## Use the upload API from your own client

[Continue to Upload API for custom clients](/upload-client-api#use-the-upload-api-from-your-own-client).
