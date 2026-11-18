---
title: "Reference Studio media from your app"
description: "Store stable media references, register usage and look up URLs when needed."
---

Choose an entry reference to follow the current file or a version reference to keep a specific version. Review [edited-version retention](/media-versions) before changing how long previous bytes are kept.

## Store references in your own application

A selected file can be used by multiple records without copying its original.
Register the application model that owns each usage:

```php
'usage_owners' => [
    'post' => App\Models\Post::class,
],
```

Save the record and its references in the same application transaction:

```php
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Library\MediaReferences;

DB::transaction(function () use ($post, $workspaceId, $selectedAssetId) {
    $post->save();

    app(MediaReferences::class)->sync(
        actor: auth()->user(),
        owner: $post,
        attribute: 'cover',
        workspaceId: $workspaceId,
        references: [[
            'assetId' => $selectedAssetId,
            'slot' => 'hero',
            'metadata' => ['alt' => 'A view of the hotel garden'],
        ]],
    );
});
```

The model's Laravel `update` policy and workspace access are checked. `slot` is
the stable identifier of an attachment within that attribute. Omit `versionId` to
follow the asset's current version; supply a version ID to pin a particular one.
Synchronizing an empty list removes that attribute's usages and keeps the shared
original. Files with registered usages cannot be moved to the trash until their
usages are removed.

Resolve many references together when rendering a page:

```php
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\MediaReferences;

$items = app(MediaReferences::class)->resolve(
    Principal::fromUser(auth()->user()),
    $workspaceId,
    [
        ['assetId' => $firstAssetId],
        ['assetId' => $secondAssetId, 'versionId' => $pinnedVersionId],
    ],
);
```

The response preserves order and repeated references. The resolver authorizes
and fetches up to120references in a bounded batch, rather than issuing one query
per image. The returned private content URLs check access when requested. A public
page needs an explicit publication/access policy; selecting a private file does
not make it public.
