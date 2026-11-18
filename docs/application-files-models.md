---
title: "Register model originals and renditions"
description: "Expose application-owned originals and variants through a bounded model projection."
---

Use model registration when your application already owns each original, conversion and access rule. To browse a configured disk directly, see [Existing disk sources](/existing-disk-sources).

## Register originals and variants on your own models

Implement `RegistersApplicationMedia` on the Eloquent model that owns the file.
The model publishes the logical image and the ready variants of that revision.
This works with your own uploader or native Filament FileUpload. Store the
paths, actual MIME types and byte lengths when your upload or conversion job
finishes so the registration can read them without accessing storage.

```php
use Illuminate\Database\Eloquent\Model;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMedia;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMediaObject;
use UseFlex\MediaStudio\ApplicationFiles\RegistersApplicationMedia;

class Post extends Model implements RegistersApplicationMedia
{
    public function studioMedia(): iterable
    {
        if ($this->cover_path === null) {
            return;
        }

        $variants = [];
        foreach ($this->cover_variants ?? [] as $name => $file) {
            $variants[$name] = new ApplicationMediaObject(
                's3-variants', $file['path'], $file['mime'], $file['bytes'],
            );
        }

        yield new ApplicationMedia(
            key: 'cover',
            revision: $this->cover_revision,
            original: new ApplicationMediaObject(
                's3-originals', $this->cover_path, $this->cover_mime, $this->cover_bytes,
            ),
            variants: $variants,
            thumbnail: 'small',
            preview: 'large',
        );
    }
}
```

In this example `cover_variants` is an array-cast attribute containing only the
ready variants for `cover_revision`. Keep the slot key stable across replacements.
Use immutable object paths and a new revision when replacing the original. A
conversion job must publish its metadata only if its source revision is still
current. The five variants can share a flat prefix with every other post. Studio
indexes the `cover` slot as one file and uses only the two configured display roles.

Register the source with the allowed storage locations and a model access scope:

```php
'posts' => [
    'type' => 'model',
    'model' => \App\Models\Post::class,
    'label' => 'Post covers',
    'revision' => 'v1',
    'permission' => 'studio.application-files.posts',
    'scope' => \App\Media\PostMediaScope::class,
    'stores' => [
        's3-originals' => 'posts',
        's3-variants' => ['posts', 'legacy-posts'],
    ],
],
```

The scope is an invokable service receiving `(Builder $query, ?TenantIdentity
$tenant, Principal $principal)` and returning the scoped Eloquent builder.
Apply the same owner and tenant restrictions that govern the original file.
For tenant-specific disks, a service provider may register a prefix callback
receiving the resolved tenant. Keep scoped model registration stateless and use
already-loaded attributes. If a gallery has many photos per post, register the
individual photo model. Each owner can register up to 32 slots with up to 16
variants each. Owners use the same database connection as the catalog.

Backfill one bounded batch at a time:

```sh
php artisan media-studio:application-files:index posts user 42 --limit=60
php artisan media-studio:application-files:index posts user 42 --limit=60 --after=123
```

Use the returned cursor. This reads the model table and does not list an S3
bucket. When a model changes or conversions finish, call
`ApplicationCatalog::syncModel($principal, 'posts', (string) $postId)` after
commit. It reloads the authoritative model and removes projections for slots
the model no longer declares. For a hard delete, call
`forgetDeletedModel($principal, 'posts', (string) $postId)` after commit to
remove the catalog records. Removed or unauthorized owners are immediately
hidden and delivery checks the current slot and revision. The owning uploader
continues to control storage and deletion.

When switching from an existing raw disk source, remove its
`allow_unregistered` flag to hide its path-based entries. Keep raw opt-in sources
disjoint from prefixes registered by a model. The catalog treats two explicitly
configured sources as different access scopes.

## Spatie models and collections

Spatie sources require an explicit Eloquent scope. The callback receives the query,
the current tenant (or `null` in a non-tenanted application), and the principal.
The example below is for a non-tenanted application exposing its Post media to
users granted the source permission:

```php
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

'spatie' => [
    'type' => 'spatie',
    'label' => 'Spatie',
    'revision' => 'v1',
    'permission' => 'studio.application-files.spatie',
    // Optional: otherwise uses media-library.media_model.
    'media_model' => \Spatie\MediaLibrary\MediaCollections\Models\Media::class,
    'scope' => static fn (Builder $query, ?TenantIdentity $tenant, Principal $actor)
        => $query->where('model_type', (new Post)->getMorphClass()),
    'model_labels' => [(new Post)->getMorphClass() => 'Posts'],
    'thumbnail_conversion' => 'thumb',
    'preview_conversion' => 'large',
],
```

Index a batch without selecting a workspace:

```sh
php artisan media-studio:application-files:index spatie user 42 --limit=60
# Continue using the returned "after" primary key:
php artisan media-studio:application-files:index spatie user 42 --limit=60 --after=123
```

Models and collections appear as separate folder levels. Custom Spatie media models,
primary keys, path generators, morph aliases and conversion disks are respected.
Existing generated conversions are used; listing does not trigger conversions.
Different models can choose their own conversions:

```php
'model_conversions' => [
    (new \App\Models\Post)->getMorphClass() => [
        'thumbnail_conversion' => 'post-thumb',
        'preview_conversion' => 'post-large',
    ],
    (new \App\Models\Product)->getMorphClass() => [
        'thumbnail_conversion' => 'product-thumb',
        'preview_conversion' => 'product-large',
    ],
],
```

The scope is rechecked in a single media query for each result page and again at
delivery. Deleted or no-longer-authorized media are not served. Changed media may
be temporarily hidden until reindexed. Empty historical folder projections can
remain. For newly created or updated media, run another bounded indexing pass or
call `ApplicationCatalog::indexSpatie()` from your trusted synchronization process.
Automatic global discovery is not implied by enabling the catalog.
