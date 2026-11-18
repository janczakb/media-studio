---
title: Private S3 storage
description: Configure a private Laravel S3 disk for Studio files, verify the write paths and choose a delivery policy.
---

Use a dedicated private S3 disk for workspace originals and the derivatives that Studio writes to it. Files uploaded through Laravel can use this disk with the default transfer settings. Enable [direct multipart](/upload-s3-multipart) separately when browsers should send large parts directly to the bucket.

## Configure the Laravel disk

Add the Laravel S3 adapter to the host application if it is missing.

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

Add a disk under `disks` in `config/filesystems.php`. This example lets the SDK resolve credentials from the deployment environment or an AWS role.

```php
'studio-s3' => [
    'driver' => 's3',
    'region' => env('STUDIO_S3_REGION', 'eu-central-1'),
    'bucket' => env('STUDIO_S3_BUCKET'),
    'visibility' => 'private',
    'throw' => true,
],
```

```dotenv
STUDIO_S3_REGION=eu-central-1
STUDIO_S3_BUCKET=company-media-production
```

Keep S3 Block Public Access enabled. Scope the application role to the bucket and Studio-owned prefixes it needs. If the app and queue workers use separate roles, give each role access to the objects its feature reads or writes. Use Laravel's `endpoint` and `use_path_style_endpoint` settings for a compatible provider that requires them. Check signed requests, checksums, range reads and deletion against that provider before enabling the corresponding workflows. [Laravel filesystem documentation](https://laravel.com/docs/13.x/filesystem#amazon-s3-compatible-filesystems)

## Select it for new workspaces

Merge this fragment into `config/media-studio.php`.

```php
'storage' => [
    'disk' => 'studio-s3',
    'root' => 'media-studio',
],
```

Existing workspaces retain their disk and root. Before migrating an existing workspace, verify its storage references and plan how to copy objects, validate both originals and derivatives, and roll back. Changing the default only affects workspaces created afterward.

## Test storage and delivery separately

1. Create a test workspace after applying the disk configuration. Upload a JPEG and verify that a signed-in viewer can open the preview and download the original.
2. If processing is enabled, wait for the thumbnail and confirm that the worker can read the original and write its output.
3. Open the unmodified object URL in a signed-out browser. Use Studio's authorized media or share routes to check the intended public or private access independently.
4. Test seeking in a supported video through the production ingress. Verify the range response and review [CDN delivery](/cdn-delivery) before adding edge caching.

Use [S3 encryption and KMS](/encryption-s3) for encryption policies. To browse objects already stored by another application, use [existing disk sources](/existing-disk-sources).

For files written to R2 and delivered through an edge binding, see [Cloudflare Worker and private R2](/cdn-cloudflare-r2).
