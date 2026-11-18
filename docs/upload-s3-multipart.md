---
title: "Direct S3 multipart uploads"
description: "Send large files from the browser to private S3 using short-lived signed parts."
---

Start with a [private writable S3 disk](/storage-s3). Configure this guide only if the browser should send large parts directly to the provider.

## Upload directly to private S3

Install the Laravel S3 filesystem adapter in the application. Configure a private
S3 disk and use it as the storage disk when creating the workspace. Existing
workspaces keep their configured disk. Enable direct multipart on the server.

```php
// config/media-studio.php
'storage' => [
    'disk' => 'studio-s3',
    'root' => 'media-studio',
],
'upload' => [
    // Keep your MIME allowlist and ordinary upload limit here.
    'sessions' => [
        'enabled' => true,
        'max_bytes' => 10 * 1024 * 1024 * 1024,
        'multipart' => [
            'enabled' => true,
            'threshold_bytes' => 100 * 1024 * 1024,
            'part_bytes' => 8 * 1024 * 1024,
            'url_seconds' => 300,
        ],
    ],
],
```

Serve the app over HTTPS so the browser can checksum each part. Studio signs the
part size and checksum and sends the bytes directly to S3. The app completes the
multipart upload and streams the assembled object once to verify its type and
digest before publishing it in the library. Keep thumbnail generation on a
background queue for large files.

Configure the bucket to allow `PUT` from the application origin and expose the
part response's `ETag` to the browser.

```json
[
  {
    "AllowedOrigins": ["https://admin.example.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["x-amz-checksum-sha256", "content-type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 300
  }
]
```

The application role needs scoped permissions to create, list parts, complete,
abort and read uploads, and to remove failed objects in the configured prefix.
Set an S3 lifecycle rule to abort incomplete multipart uploads after a period
longer than the maximum session duration. This also covers interrupted
initialization where the application never receives an upload ID.

## When Studio chooses direct transfer

For a new session, Studio selects this transport when the client requests direct transfer, the file meets the threshold, and the workspace resolves to a writable private S3 adapter. A client should branch on the returned `transport` field. The part size must be between 5 and 32 MiB. Signed URLs are bounded to 30–900 seconds and may expire sooner when the session expires. [Upload API for custom clients](/upload-client-api)

## IAM and lifecycle

For multipart operations on the Studio object prefix, review `s3:PutObject`, `s3:ListMultipartUploadParts`, `s3:AbortMultipartUpload`, `s3:GetObject`, and `s3:DeleteObject`. If S3 bucket versioning is enabled, the cleanup path can also remove a specific version and needs `s3:DeleteObjectVersion`. Additional permissions depend on the rest of your storage features and on [KMS](/encryption-s3#s3-with-a-customer-managed-kms-key). [AWS multipart permissions](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpuoverview.html#mpuAndPermissions)

For a one-day Studio session TTL, a bucket lifecycle rule can abort incomplete uploads under the managed prefix after three days. Schedule [Studio session pruning](/upload-recovery#schedule-staging-cleanup) as well. The bucket rule covers abandoned multipart uploads at the provider, including those Studio could not record.

## Verify and diagnose

| Observation | Check |
| --- | --- |
| Session returns `local` | Workspace disk and visibility, multipart settings, client `direct` flag and threshold. |
| Browser shows a CORS error | Match the exact page origin, allow PUT and the checksum header, and expose ETag. |
| S3 rejects a signed PUT | Request a fresh URL. Preserve the returned header and send the exact bytes used for the checksum and signed size. |
| Every part succeeds but the asset stays unpublished | Inspect the completion request and backend logs. Check the role's list/read access and the time available for Laravel to stream and verify the assembled object. |
| Incomplete uploads accumulate | Inspect both app-side pruning and the bucket's rule for incomplete multipart uploads. |

Test from the real browser origin. Upload a file above the threshold, confirm the part PUTs target the bucket, and verify the file appears in Studio only after completion. Test resuming an interrupted part and downloading the verified original. [AWS CORS configuration](https://docs.aws.amazon.com/AmazonS3/latest/userguide/ManageCorsUsing.html)
