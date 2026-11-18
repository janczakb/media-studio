---
title: "Browse an existing Spatie Media Library"
---

Mount a Spatie collection to show its files in Media Studio. The files stay in
their configured disks and Spatie keeps ownership of the records and
conversions. The mount appears as a read-only folder in a workspace.

## Choose a collection and access scope

Register a mount in `config/media-studio.php`.

```php
'integrations' => [
    'spatie' => [
        'mounts' => [
            'products' => [
                'label' => 'Product photos',
                'scope' => App\Media\ProductMediaScope::class,
                'thumbnail_conversion' => 'thumb',
                'preview_conversion' => 'large',
                // 'media_model' => App\Models\CustomMedia::class,
            ],
        ],
    ],
],
```

Studio uses Spatie's configured media model by default. The scope is required
and runs with the authenticated principal on sync and on reads. In this example,
products have an indexed `studio_workspace_id` column storing the workspace's
public ID, and workspace readers may view product photos. Apply any additional
per-user rules inside the same scope.

```php
namespace App\Media;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use UseFlex\MediaStudio\Identity\Principal;

final class ProductMediaScope
{
    public function __invoke(Builder $media, object $workspace, Principal $principal): Builder
    {
        return $media
            ->where('collection_name', 'photos')
            ->whereHasMorph('model', [Product::class], fn (Builder $products) =>
                $products->where('studio_workspace_id', $workspace->public_id)
            );
    }
}
```

## Sync in batches

An owner or editor can call the workspace's sync endpoint. Send the returned
`nextCursor` with the next request. A null cursor marks the end of a pass.

```http
POST /media-studio/api/v1/workspaces/{workspace}/sources/spatie/products/sync
Content-Type: application/json

{"limit":60}
```

In a Laravel job, the same operation is available through `SourceIndexer`.
Store the returned cursor between batches. For ongoing synchronization,
schedule fresh passes or run a pass after your application's import completes.

```php
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Spatie\SourceIndexer;

$page = app(SourceIndexer::class)->syncPage(
    Principal::fromUser($actor),
    $workspaceId,
    'products',
    $previousCursor,
    60,
);
```

After Spatie finishes a queued conversion, run another pass to show the
generated thumbnail. Deleting a Spatie record or revoking source access makes
it inaccessible in Studio on subsequent requests. To add, rename or remove a
mounted file, use your application's Spatie workflow.
