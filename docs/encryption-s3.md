---
title: "S3 encryption and KMS"
description: "Use bucket defaults, configure SSE-S3, or use a customer-managed KMS key."
---

Choose the encryption policy separately from your [private disk](/storage-s3) and [upload transport](/upload-s3-multipart). Start with a test object from each enabled write path.

## Reuse a provider's automatic encryption

```php
'encryption' => [
    'mode' => 'infrastructure',
    'disks' => [
        'studio-private' => ['driver' => 'provider-default'],
    ],
],
```

This retains the disk/provider configuration exactly; it does not layer another
cipher or assert that the provider is verified. For example, Amazon S3 encrypts new
uploads by default. Other S3-compatible providers have their own guarantees and
supported APIs. Check the provider and historical objects; changing a bucket's
policy does not by itself prove every older object has the intended protection.

## S3-managed encryption

```php
'encryption' => [
    'mode' => 'infrastructure',
    'disks' => ['studio-private' => ['driver' => 'sse-s3']],
],
```

This explicitly sends `ServerSideEncryption=AES256`. Use only a provider that
supports this API. Unsupported/rejected requests fail; there is no unencrypted
retry fallback. Explicit SSE profiles require HTTPS with certificate verification.

## S3 with a customer-managed KMS key

```php
'encryption' => [
    'mode' => 'infrastructure',
    'disks' => [
        'studio-private' => [
            'driver' => 'sse-kms',
            'kms_key_id' => env('FMS_S3_KMS_KEY_ARN'),
            'bucket_key' => true,
        ],
    ],
],
```

Provide the canonical key ARN, not an alias, so stored-object metadata can be
compared to the intended key. Configure IAM/key permissions, bucket policy,
provider-side key rotation, backups and recovery separately. KMS can incur charges.
S3 Bucket Keys are optional for SSE-KMS. For DSSE-KMS use `driver => 'dsse-kms'`
and omit `bucket_key`, which is not supported for that profile.

Native parameters are enforced at SDK write initialization, covering ordinary
uploads, SDK copies and multipart initiation. Studio's direct multipart pipeline
also propagates existing Laravel SSE options when mode is off, binds encryption
options into the session target identity and checks encryption metadata before
publication. Changing encryption parameters during a direct upload invalidates
that session's target; do not rotate configuration mid-session.

The policy compiles internally to `fms-s3-native`, preserving Laravel's S3 adapter,
streaming, HTTP ranges and temporary URLs. It does not download/re-encrypt large
files in PHP. Provider-side settings and real storage behavior must still be
verified in the deployment. Tests use the real AWS SDK with mocked responses,
not a live AWS/KMS account.
