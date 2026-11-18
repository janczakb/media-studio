---
title: "AI input selection and upload lifecycle"
---

## Select an existing conversion

AI input selection is server-side. It is independent of Filament and works with
Studio's existing external-variant contract. All sources are converted to a
bounded, stripped temporary provider-compatible copy; persistent AVIF/WebP files
and originals remain unchanged.

```php
'intelligence' => [
    // Keep provider, permissions and queue settings.
    'input' => [
        'type' => 'rendition', // auto, original, rendition, provider
        'preset' => 'medium-v1',
        'missing' => 'wait', // wait, skip, temporary
    ],
],
```

- `auto`: prefer the named ready conversion, otherwise apply `missing`.
- `rendition`: select the named current-version conversion; external projections
  are revalidated using their registered owner before use.
- `original`: prepare a temporary analysis copy from the current original under
  source byte/pixel limits. Never sends an unlimited original directly.
- `provider`: ask a registered `ExternalVariantProvider` for the named conversion.

`wait` returns `waiting_input`; `skip` returns `skipped_input`. Neither fetches an
original or calls the paid provider when the selected conversion is missing.
`temporary` explicitly permits creating the analysis copy from the original.
Waiting is a persisted job result, not an automatic readiness subscription yet;
after conversion completion the host must explicitly rerun analysis. A cropped
conversion analyzes only the visible crop: choose an uncropped rendition if the
whole scene matters.

## Spatie and custom conversion systems

```php
'processing' => [
    // Preserve your other processing settings.
    'external_providers' => [
        'my_media' => App\Media\MyAnalysisVariants::class,
    ],
],
'intelligence' => [
    // Preserve provider settings.
    'input' => [
        'type' => 'provider',
        'provider' => 'my_media',
        'preset' => 'analysis',
        'missing' => 'wait',
    ],
],
```

The provider implements `UseFlex\MediaStudio\Renditions\ExternalVariantProvider`.
It receives the authorized workspace, entry, immutable version and principal.
It must resolve the linked Spatie/custom record itself, check ownership and
conversion readiness, and return `ExternalVariant` objects. Each describes a
configured Laravel disk and relative object key, MIME, actual byte size and an
SHA-256 digest. Digest is mandatory for AI input even though ordinary display
variants allow it to be null. Prepare/store the digest when the conversion is
created; do not hash all source files on gallery requests.

This supports custom models/disks/conversion names without requiring every
uploader to use Studio's converters. There is not yet a generic automatic
Spatie conversion adapter or automatic external FileUpload-to-Studio identity
registration. Analysis currently starts from a managed Studio entry; do not
represent an arbitrary storage path as an owned entry without an explicit host
integration. OCR will need its own input profile and is not connected yet.

## Removing or replacing files

Native Filament FileUpload does not guarantee deleting removed files from disk.
See its [file deletion responsibility](https://filamentphp.com/docs/5.x/forms/file-upload).
Removing a form item, saving a relationship, deleting a media record and physically
deleting bytes are separate operations. Third-party uploaders may behave differently.

The host integration must register the saved asset identity/version after a
successful model transaction, not on temporary upload state changes. On avatar
replacement, publish the new version/association first; cancel old processing
and remove its search projection, then let the authoritative owner delete old
bytes only if no references remain. A form canceled before save must not destroy
the existing avatar. Same storage path reused for new bytes needs a new version.

For galleries, removal should detach the saved reference. It deletes the original
only under the configured ownership/reference policy. Spatie owns its original
and conversions; Studio must not independently delete those paths. Custom systems
need equivalent saved/deleted events and a reconciliation adapter for missed events.

Current Studio managed analysis checks trash/current version and refuses stale
publication. Source synchronization and search result authorization protect indexed
external sources under their existing contracts. This does not yet constitute a
universal FileUpload deletion bridge, shared-reference GC or automatic AI cleanup
for every third-party plugin. Until that bridge is implemented, the host must
explicitly supply lifecycle events; no uploader-independent deletion guarantee
is made.

For user avatars, keep AI tags off by default. Enable only when their recognition
serves an explicit application feature; a profile picture does not require
content analysis simply because it was uploaded.
