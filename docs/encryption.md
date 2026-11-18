# Encryption configuration

Encryption policy defaults to `off`. It never disables encryption already provided
by your hosting service, database or object storage. No existing objects are
rewritten when changing this setting.

**Current scope:** native S3 write policies are implemented. Portable application
file encryption is experimental and separately gated pending independent security
review. This is not yet a complete encrypted Studio deployment: database payload protection and resumable owned-file migrations are implemented below;
metadata/search, cache, host temporary storage, recovery-copy retirement and backups
still need explicit protection. Do not display “Fully encrypted” based on this setting alone.

## Modes

Set `encryption.mode` in `config/media-studio.php`, or `FMS_ENCRYPTION_MODE`:

| Mode | Behavior |
| --- | --- |
| `off` | Studio does not apply an encryption policy; host/provider settings remain in force. Default. |
| `infrastructure` | Apply explicit native policies to named Laravel disks. Database/volume protection is configured at the host/provider. |
| `application` | Permit the experimental `fms-encrypted` file driver when its separate gate is enabled. |
| `hybrid` | Combine native storage policies with the experimental file driver. |

Policies target dedicated private Laravel disk names. Do not point Studio at a
shared disk whose consumers need different policies. Configure before disks are
resolved; after changing configuration, rebuild the host's configuration cache and
restart long-lived queue/application workers.

## Reuse a provider's automatic encryption

[Continue to S3 encryption and KMS](/encryption-s3#reuse-a-provider’s-automatic-encryption).

## S3-managed encryption

[Continue to S3 encryption and KMS](/encryption-s3#s3-managed-encryption).

## S3 with a customer-managed KMS key

[Continue to S3 encryption and KMS](/encryption-s3#s3-with-a-customer-managed-kms-key).

## Encrypted local volume

```php
'encryption' => [
    'mode' => 'infrastructure',
    'disks' => ['studio-private' => ['driver' => 'encrypted-volume']],
],
```

This declares use of an externally encrypted local volume and validates that the
disk is local. It does **not** enable, inspect or certify volume encryption. The
operator must configure and verify it; PHP cannot do that portably on shared
hosting. Include the database, temporary processing/upload paths, caches,
search/queue persistence, swap and backups in the deployment design.

## Experimental portable application file driver

Not production-ready or independently audited. This opt-in driver requires PHP
OpenSSL with AES-256-GCM. It has no runtime Java/Python/Go process or cloud service
requirement. The independent PHP primitive follows the published Tink streaming
format and has bidirectional reference interoperability tests.

```php
// config/filesystems.php — private backing disk must be dedicated to ciphertext.
'disks' => [
    'studio-ciphertext' => [
        'driver' => 'local',
        'root' => storage_path('app/studio-ciphertext'),
        'visibility' => 'private',
        'throw' => true,
    ],
    'studio-encrypted' => [
        'driver' => 'fms-encrypted',
        'backing_disk' => 'studio-ciphertext',
        'scope' => 'your-unique-application/studio-content',
        'segment_bytes' => 65536,
        'throw' => true,
    ],
],

// config/media-studio.php — isolated evaluation only until security review.
'encryption' => [
    'mode' => 'application',
    'experimental_file_driver' => true,
    'active_key' => 'v1',
    'keys' => ['v1' => env('FMS_CONTENT_KEY_V1')],
],
'storage' => ['disk' => 'studio-encrypted', 'root' => 'flex-media-studio'],
```

The key is a separately generated random 32-byte secret encoded as `base64:...`.
Do not reuse APP_KEY or the public test fixture key, keep content keys outside
source control/webroot, and maintain a separately protected recovery copy. A
bounded keyring retains old reader keys while new writes use the active key.
Changing the active key does not rewrite existing files. Do not remove old keys or
switch off this mode while data still requires them.

Only files actually written through the encrypted disk are protected. Configure
all derivatives, archives and attachment destinations appropriately; existing
workspace records keep their stored disk references. Use the explicit resumable migration below for existing catalogue files; never
repoint an existing plaintext disk at this driver. Plaintext reads, wrong keys or invalid authentication fail closed.

Reads authenticate segments before returning plaintext and support seek/ranges;
opening also verifies the final segment. File size and approximate content length
are not secret. Object paths/IDs remain visible to the backing storage. Metadata
in the database is not protected by this driver. The driver stages ciphertext on
write and does not spool whole decrypted read files. Other processing components,
PHP's initial upload handling and operating-system temporary storage are separate
protection boundaries.

For hybrid evaluation, use `mode => 'hybrid'` with the same encrypted disk backed
by a native-policy S3 disk. Application ciphertext must be served through Studio,
so CDN/direct URL redirects and native browser multipart upload bypasses are not
used for the application-encrypted logical disk. Native-only profiles retain those
paths.

References: [Tink streaming AEAD](https://developers.google.com/tink/streaming-aead/aes_gcm_hkdf_streaming),
[AWS SSE-S3](https://docs.aws.amazon.com/AmazonS3/latest/userguide/UsingServerSideEncryption.html),
[AWS SSE-KMS](https://docs.aws.amazon.com/AmazonS3/latest/userguide/UsingKMSEncryption.html).

## Database payloads

After applying the new package and optional assistant migrations, set
`encryption.database.enabled => true` in application/hybrid mode. Default false.
Laravel AES-256-GCM seals versioned JSON envelopes bound to table, record ID and
field. Content keys are separate from APP_KEY and use a distinct HKDF domain.

Covered payloads: chat titles/messages, partial responses and stream events,
run context/usage/errors, action results/Undo/tool receipts, comment body/document/
mentions, note documents and preview excerpts. Conversation title prefix search uses
bounded keyed tokens scoped to workspace/principal, retaining indexed cursor pages.
Tokens leak equality/prefix relationships. File names and other indexed metadata
are not encrypted by this payload layer. This is not encrypted arbitrary SQL search.

Existing records require a controlled transition: temporarily set
`encryption.database.allow_plaintext_reads => true`, enable encrypted new writes,
restart long-lived workers, then run bounded invocations until completed:

```sh
php artisan media-studio:encryption:database --batch=25 --seconds=5
php artisan media-studio:encryption:database --resume=OPERATION_ULID --batch=25 --seconds=5
```

Then disable plaintext reads. Each row and checkpoint commit together; a failure
retains the failed-record checkpoint. Leases prevent simultaneous advancement.
Retain old keys when rotating; select a new active key and start a new operation.
Do not change keys during an operation. SQL logs, replicas and old backups require
independent encryption/retirement; updating a row cannot remove historic plaintext.

New AI attachments are encrypted in this mode and decrypted in memory for the
provider within the existing 8 MiB/file and 16 MiB/turn limits. Legacy attachments
must expire or be discarded/re-uploaded first. The original PHP/webserver upload
buffering remains a host-level boundary. Authorized AI providers receive plaintext.

## Resumable migration of owned files

[Continue to Encryption migration and diagnostics](/encryption-migration#resumable-migration-of-owned-files).

## Coverage diagnostics

[Continue to Encryption migration and diagnostics](/encryption-migration#coverage-diagnostics).
