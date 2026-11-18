---
title: "Bunny delivery"
description: "Configure exact-file tokens for a Bunny pull zone."
---

Use the Bunny profile in [Delivery profiles](/cdn-delivery#configure-profiles) as the starting point. Assign that profile to the image roles you want to serve through Bunny.

## Bunny

The Bunny adapter uses exact-file `HS256` HMAC-SHA256 token authentication. Enable compatible token authentication on the pull zone. The configured token key must be at least 16 characters; use a strong generated key. Include `fmsv` in the CDN cache key. No directory token or token-ignore-parameters shortcut is issued.

## Configure a pull-zone profile

Add the profile under `delivery.profiles` and point a media role to it.

```php
'workspace-images' => [
    'driver' => 'bunny',
    'url' => 'https://images.example.com',
    'token_key' => env('STUDIO_BUNNY_TOKEN_KEY'),
    'revocation' => 'expiry',
    'ttl_seconds' => 120,
],
```

Request a thumbnail through Studio, verify the signed redirect and check that the same CDN address without its token is denied. Replace the source with a new version and verify its delivered URL has a different `fmsv` value.
