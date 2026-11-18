---
title: "Resumable uploads through Laravel"
description: "Configure chunk size, session limits, temporary space and workspace overrides."
---

Use this guide when your web application receives the file parts. The same session can also publish to a private S3 disk through Laravel.

## Upload in chunks through Laravel

Chunked uploads are off by default. Once enabled by the server, **Settings →
Uploads** lets authorized workspace managers enable the feature or set a lower
size limit for new chunked sessions. Leave the limit blank to inherit the server
limit. Resuming a previously created session keeps its original part size.
The same screen shows the server's part size, part count and parallel transfer
limits and the direct S3 threshold when configured.

Use this configuration for a private local disk or when transfers must pass
through your application's server. Studio stores each received part in the staging
directory and skips accepted parts when the same session resumes.

```php
// config/media-studio.php
'upload' => [
    'max_kilobytes' => 25 * 1024,
    'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    'sessions' => [
        'enabled' => true,
        'chunk_bytes' => 5 * 1024 * 1024,
        'max_bytes' => 2 * 1024 * 1024 * 1024,
        'max_parts' => 1_000,
        'ttl_seconds' => 86_400,
        'max_active' => 12,
        'max_concurrent' => 3,
        'max_reserved_bytes' => 10 * 1024 * 1024 * 1024,
        'staging_directory' => storage_path('app/media-studio-upload-parts'),
    ],
],
```

Set the HTTP request limit above your configured part size, and allow room for
ordinary uploads at `max_kilobytes`. Keep staging private and give it enough
space for concurrent transfers. `max_active` limits open sessions per user.
`max_concurrent` caps transfers in the browser, and `max_reserved_bytes` bounds
bytes reserved by in-progress sessions across the workspace. In a multi-server deployment, every web process
that can handle a session must see the same staging files.

## Choose a part size

At 5 MiB per part, a 2 GiB file needs 410 parts. That fits the 1,000-part limit in the example. Check both limits together when you change the maximum file size. The proxy, Laravel and the staging volume must tolerate the configured part size and expected concurrency.

To verify resumption, start a large upload, allow several parts to finish, then pause and resume it in the same browser tab. The network panel should show a status request followed by PUTs for the remaining parts. Check that a workspace with a lower limit rejects a new session above that limit. [Upload recovery](/upload-recovery)
