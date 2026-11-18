---
title: "Use existing thumbnails and conversions"
description: "Reuse app, Spatie and FLEX Fields renditions in the grid and preview."
---

Register external derivatives where the owning application generates and stores them. Use the smallest suitable rendition for a grid and reserve larger variants for the viewer.

## Reuse your application's generated thumbnails

Implement `ExternalVariantProvider` when an existing job, conversion service or
legacy image system already creates your files. The provider returns metadata
for existing objects and checks access to the underlying application record.
Studio calls it while syncing the catalogue and again before delivering a file.
Return immutable, version-specific object paths so an accepted variant keeps
the same bytes for its source version.

The following example assumes your application has a `GalleryImage` model with
`studio_asset_id`, `workspace_id`, `owner_key`, `original_sha256`, and a
`conversions` relation. Each conversion record has `name`, `disk`, `path`,
`mime_type`, `size_bytes`, `width` and `height` columns.

```php
namespace App\Media;

use App\Models\GalleryImage;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Renditions\ExternalVariant;
use UseFlex\MediaStudio\Renditions\ExternalVariantProvider;

final class GalleryVariants implements ExternalVariantProvider
{
    public function variants(
        object $workspace,
        object $entry,
        object $version,
        Principal $principal,
    ): iterable {
        $image = GalleryImage::query()
            ->where('studio_asset_id', $entry->public_id)
            ->where('workspace_id', $workspace->public_id)
            ->where('owner_key', $principal->key())
            ->where('original_sha256', $version->sha256)
            ->firstOrFail();

        return $image->conversions()->where('state', 'ready')->limit(65)->get()
            ->map(fn ($file) => new ExternalVariant(
                name: $file->name,
                disk: $file->disk,
                objectKey: $file->path,
                mimeType: $file->mime_type,
                sizeBytes: $file->size_bytes,
                width: $file->width,
                height: $file->height,
            ));
    }
}
```

Register the provider and choose which of its names supplies the two display roles.
Call sync after your own job finishes producing the variants. The original asset
must already be registered in the Studio workspace.

```php
// config/media-studio.php, inside processing
'external_providers' => [
    'gallery' => App\Media\GalleryVariants::class,
],
```

```php
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Renditions\Renditions;

app(Renditions::class)->syncExternal(
    principal: Principal::fromUser($actor),
    workspaceId: $workspaceId,
    entryId: $studioAssetId,
    provider: 'gallery',
    roles: ['thumbnail' => 'small', 'preview' => 'large'],
);
```

External variants stay owned by their source. Sync updates Studio's index and
keeps the files in place. Return only ready images for the exact current source
version. Returning an empty list and syncing again clears the display pointers.
Revoked access is checked before delivery even if the last synced card remains
visible. Providers are registered server-side and accept a maximum of 64 variants.

Choose one writer for each rendition name. If an external pipeline owns all
conversions, disable native generation.

```php
'native' => ['enabled' => false],
```

For a mixed library, `native.enabled` can be an invokable class or a closure
receiving the workspace, entry and principal and returning a boolean. Prefer an
invokable class in cached Laravel configuration. Return `true` for new Studio
uploads and `false` for assets whose existing external pipeline owns conversions.

## Reuse Spatie conversions

Add the conversion names to your
[Spatie mount](/spatie-media-library).

```php
'products' => [
    'scope' => App\Media\ProductMediaScope::class,
    'thumbnail_conversion' => 'thumb',
    'preview_conversion' => 'large',
],
```

Spatie continues creating its conversions, including on its queue. Sync the mount
after conversion completion so Studio can show the generated files. The original
and conversion disks may differ. Pending conversions display the available
thumbnail or a placeholder.

## Reuse FLEX Fields conversions

The optional `UploadManifestVariants` provider reads FLEX Fields' authenticated
upload manifests and their saved receipts. Bind it with the owning application's
authorization callback and register the binding as an external provider.

```php
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaReceipt;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\FlexFields\UploadManifestVariants;

$this->app->bind(UploadManifestVariants::class, fn () =>
    new UploadManifestVariants(
        authorize: function (
            object $workspace,
            object $entry,
            object $version,
            Principal $principal,
            MediaReceipt $receipt,
            string $name,
        ): bool {
            return \App\Models\GalleryImage::query()
                ->where('studio_asset_id', $entry->public_id)
                ->where('workspace_id', $workspace->public_id)
                ->where('owner_key', $principal->key())
                ->where('original_sha256', $version->sha256)
                ->exists();
        },
    )
);
```

```php
'external_providers' => [
    'flex' => UseFlex\MediaStudio\Integrations\FlexFields\UploadManifestVariants::class,
],
```

Sync using the FLEX conversion names as the thumbnail and preview roles. The
indexed version must match the manifest's primary receipt, including disk, path,
MIME, byte size and hash. This also covers uploads that use an optimized primary.

For queued FLEX conversions, also pass `queueAuthority` to the provider. Its
callback receives the workspace, entry, version, principal, original receipt and
journal ID, and returns the `MediaWrite` authorized by the owning application's
Media OS policy. The adapter uses FLEX's native snapshot checks and exposes
variants after the journal reaches `ready`. Sync again after completion. Keep
that authority consistent with the file's record, field and policy.

Use a Spatie mount for FLEX fields backed by Spatie Media Library. The disk-manifest
adapter handles FLEX's disk upload receipts.

## Supply display URLs from a custom frontend transport

The React SDK accepts `thumbnailUrl` and `previewUrl` on each entry. Listing uses
`thumbnailUrl` only. Set `previewUrl` to the ready display rendition or `null`
while it is unavailable. Set `previewState` to `queued` or `processing` while your
worker runs, and return the updated entry from `transport.status` when ready.
The SDK refreshes visible pending cards and the selected preview. Keep
`contentUrl` connected to the original for downloads.
