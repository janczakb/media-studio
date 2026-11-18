---
title: "CloudFront delivery"
description: "Use a private origin and signed CloudFront URLs."
---

Start with [delivery profiles](/cdn-delivery#configure-profiles), then add this profile under `delivery.profiles`. Assign it to a role such as `preview` or `video`.

## CloudFront

```php
'private-cloudfront' => [
    'driver' => 'cloudfront',
    'url' => 'https://media.example.com',
    'key_pair_id' => env('STUDIO_CLOUDFRONT_KEY_ID'),
    'private_key' => env('STUDIO_CLOUDFRONT_PRIVATE_KEY'), // PEM contents
    'revocation' => 'expiry',
    'ttl_seconds' => 120,
],
```

Register the matching public key in a trusted CloudFront key group and protect the origin against direct public access. The signer uses an exact-resource canned policy with RSA of at least 2048 bits and SHA-256. It does not grant directory-wide access. Configure the distribution's cache key to include `fmsv`, while signature parameters are handled by CloudFront authentication.

## Verify signed delivery

Test a file through Studio and follow the redirect. Confirm the application returns a signed URL for that exact object and the origin cannot be opened directly. Open the URL after its TTL and request the same file again through Studio to obtain a fresh URL. If CloudFront denies a fresh URL, check the key group, the configured key ID, the private key, and whether the distribution serves the exact encoded path in the URL.
