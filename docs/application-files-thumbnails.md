---
title: "Generate thumbnails for application files"
description: "Configure bounded on-demand thumbnail generation and verify the output disk."
---

Register existing thumbnails when your application already creates them. Enable this on-demand pipeline for eligible sources that need Studio to generate missing derivatives.

## Generate missing thumbnails on demand

Existing registered thumbnails and Spatie conversions take priority. For images
without one, Studio can generate a cached thumbnail when the file enters the
visible virtualized grid or an open preview. Generation is disabled by default.

Publish the optional cache migration in addition to the Application files migrations.

~~~sh
php artisan vendor:publish --tag=media-studio-application-thumbnail-migrations
php artisan migrate
~~~

Configure a Laravel disk for generated files. Local storage and S3 are supported.
The cache disk can differ from the originals' disks.

~~~php
'application_files' => [
    'enabled' => true,
    'sources' => [
        // Your registered sources.
    ],
    'thumbnails' => [
        'enabled' => true,
        'disk' => 's3', // Use 'local' for local storage.
        'prefix' => 'studio-cache/application-thumbnails',
        'format' => 'avif', // Or 'webp'.
        'edge' => 400,
        'quality' => 40,
        'avif_effort' => 3,
        'driver' => 'auto', // Or 'gd' / 'imagick'.
        'connection' => 'database', // Or a configured Redis/SQS connection.
        'queue' => 'media-application-thumbnails',
        'max_source_bytes' => 12 * 1024 * 1024,
        'max_source_pixels' => 16_000_000,
        'max_output_bytes' => 256 * 1024,
        'max_cache_bytes' => 1024 * 1024 * 1024,
        'max_cache_entries' => 25_000,
        'max_pending' => 128,
        'ttl_minutes' => 1440,
        'schedule_pruning' => true,
    ],
],
~~~

JPEG, PNG, WebP and AVIF inputs within these limits qualify. The PHP encoder must
support the input and output formats. Aspect ratio is preserved and small images
keep their existing size. Metadata is stripped. Studio verifies the encoded MIME
type and dimensions before publishing the result.

Run the configured worker and Laravel scheduler.

~~~sh
php artisan queue:work database --queue=media-application-thumbnails --timeout=90
php artisan schedule:work
~~~

Set the worker timeout below the queue connection's retry_after or visibility
timeout. Automatic generation requires a background queue. With a synchronous or
disabled queue, the grid shows file icons for missing thumbnails. Existing
registered or cached thumbnails remain available. Original image previews open
through the authorized original route.

Disable fallback generation for one source by setting generate_thumbnails to false
in that source's configuration.

### Cache limits and cleanup

The byte budget and entry limit are shared across the application's cached
thumbnails. Admission reserves the maximum output size before dispatching a job.
Repeated visible requests share the same job and cache record. At most 16 jobs are
admitted per request, subject to pending-job and storage limits.

Keys include the tenant-scoped catalog identity, source revision, file descriptor,
thumbnail profile and cache location. Changed versions or profiles receive new
keys. Workers check current access, tenant membership and registered media before
reading and again before publication. Losing access blocks delivery of cached
thumbnails as well.

Expired entries are removed in batches. When admission reports a full cache, the
cleanup task can reclaim the oldest ready entries. Failed deletions retain their
budget charge and retry after a delay. Original files and registered variants
are outside this cleanup operation.

The scheduler registers cleanup every minute when enabled. It can also run
explicitly, including after disabling generation.

~~~sh
php artisan media-studio:application-files:prune-thumbnails --limit=100
~~~

These limits account for current objects managed by this cache. S3 versioning can
retain noncurrent versions after deletion. Configure the bucket's noncurrent
version lifecycle separately when versioning is enabled. Clean the old cache
before changing a cache disk's physical location, or retain that disk configuration
until cleanup completes. Lowering limits stops new admission while cleanup
reclaims existing objects over successive batches.

### Prepare a batch without a queue worker

A scheduled command or trusted operator can prepare small batches explicitly.
This runs conversion inside the command process. It uses the same storage budget,
image limits, source scopes and access checks.

~~~sh
php artisan media-studio:application-files:warm-thumbnails posts user 42 --limit=16
~~~

The actor needs the department, source and indexing permissions. The JSON output
contains visited and ready counts and a nextAfter row ID. Pass that ID as
--after for the next batch. A null nextAfter means the current batch reached the
end. This permits controlled incremental preparation without scanning a bucket
or converting images during page rendering.
