---
title: "Cloudflare Worker and private R2"
description: "Configure per-request authorization and a private R2 binding for Studio media."
---

Use this guide to deliver existing Studio media from private R2. To configure the Laravel write disk, see [Private S3 storage](/storage-s3).

## Cloudflare Worker with private R2

The optional standalone Worker source lives in the repository at `packages/cloudflare-worker`; it is deployed separately and is not installed on Cloudflare by Composer. It is independent of React, Filament and FLEX Fields. It reads private R2 bindings; it does not require making a bucket publicly accessible.

```php
'private-edge' => [
    'driver' => 'worker',
    'url' => 'https://media.example.com',
    'token_key' => env('STUDIO_EDGE_TOKEN_KEY'),
    'revocation' => 'per_request',
    'ttl_seconds' => 120,
    'revision' => 1,
],
```

Configure the Worker using its example configuration, real R2 bindings and these values:

- `TOKEN_KEY`: a secret matching `token_key`; set through the platform's secret mechanism.
- `AUTHORIZE_URL`: the HTTPS Studio endpoint `/media-studio/api/v1/delivery/check`, adjusted for your route prefix.
- `DISK_BINDINGS`: a JSON mapping of Laravel disk names to Worker binding names and their physical root prefix.
- `CORS_ORIGINS`: an explicit comma-separated list of your application origins.
- `MAX_CACHE_BYTES`: maximum full inline object size admitted to the edge cache; default 2 MB, capped at 10 MB.
- `CACHE_NAMESPACE`: change this when intentionally replacing origin data outside normal Studio versioning.

For example: `{"media-s3":{"binding":"MEDIA","prefix":"tenant-root/"}}`. Use an empty prefix when the Laravel disk has no root prefix. Mapping and namespace participate in the edge cache identity.

The Worker validates the URL signature, audience and expiry before touching R2 or cache. In its default strict mode, it also exchanges an opaque encrypted grant with Studio before every request, including cache hits. Studio reloads the principal and checks current workspace, tenant, source, version, share and operation permissions. Database or network failure blocks access rather than serving stale private data. This adds one authorization request to Studio per edge request; it saves byte transfer through PHP, not all backend work.

Only small full inline responses enter the Worker cache. Byte-range responses and downloads stream directly from R2. Successful browser responses remain private and non-cacheable; the independently authorized edge cache may retain immutable bytes. Range requests and HEAD are supported. Prepared ZIP archives keep the existing server fingerprint checks in `per_request` mode; use the S3 `expiry` profile if you want to offload a prepared archive.

For explicitly accepted expiry-only behavior, set both the PHP profile to `revocation => 'expiry'` and the Worker variable `AUTHORIZATION_MODE=expiry`. Do not change the Worker to expiry-only when the application expects strict checks. Increase the profile revision to invalidate existing strict grants after a policy deployment.

The authorization endpoint is a dedicated encrypted-capability exchange, not a browser login endpoint. Keep HTTPS and the configured application key stable across instances. Its request rate uses Laravel's shared cache; configure Redis in a cluster. Set `delivery.edge_authorization_limit` for your traffic (default 6000 requests per IP per minute; range 1–60000). Account for shared edge egress IPs when sizing it. Do not cache its responses at your proxy.

## Test revocation on warm cache

Use a test workspace and a small inline image so the edge can cache the object. Open it through Studio, then verify repeat requests remain authorized. Remove the test account's workspace or source access and retry the still-unexpired address. In the default `per_request` mode the next request must be denied even with the bytes cached. Test a video range separately because ranges bypass the small-object cache.
