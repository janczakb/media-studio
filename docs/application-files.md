---
title: "Application files"
---

Use **Application files** to browse files owned by your application or Spatie Media
Library. This catalog is independent of workspaces. It has its own permissions,
folders and database index. Opening it does not create a workspace or add workspace
memberships.

The catalog is read-only. Studio does not rename, move, replace, share or delete
these originals. Your application or Spatie remains responsible for their lifecycle.
Files uploaded into Studio workspaces continue to use the normal workspace library.

## Enable the catalog

The feature is disabled by default. Publish its optional migration explicitly:

```sh
php artisan vendor:publish --tag=media-studio-application-migrations
php artisan migrate
```

Register your Eloquent models or a Spatie source in `config/media-studio.php`.
Application Files lists registered media only. A raw disk source is a separate,
explicit opt-in for libraries that intentionally list standalone objects:

```php
'application_files' => [
    'enabled' => true,
    'sources' => [
        'avatars' => [
            'type' => 'disk',
            'allow_unregistered' => true,
            'label' => 'User avatars',
            'revision' => 'v1',
            'permission' => 'studio.application-files.avatars',
            'disk' => 'public', // S3 disks are supported too.
            'prefix' => 'avatars',
        ],
    ],
],
```

Source keys use lowercase letters, digits, underscores and hyphens, up to 64
characters. A source requires an explicit `revision`. Change this value when you
change its ownership or access scope. Old projections then stop being served until
you index that source again. Disk, prefix and storage-location changes also change
the source fingerprint automatically.

## Permissions

Run the normal permission installer with your application's authentication guard:

```sh
php artisan media-studio:permissions --guard=web
```

This creates definitions through the configured Spatie Permission model; it does
not grant access to users automatically. Assign the required permissions to your
roles:

| Permission | Allows |
| --- | --- |
| `studio.application-files.view` | Opening the department |
| A source's configured `permission` | Seeing that source |
| `studio.application-files.download` | Using download actions |
| `studio.application-files.index` | Updating the catalog from trusted application code or CLI |

Viewing an image necessarily sends its bytes to the browser. Hiding download
actions cannot prevent saving an image that someone is authorized to view.

An optional `authorize` callback can restrict a source further. It receives the
server-resolved tenant and the authenticated `Principal` and must return `true`.
Callbacks must be stateless and work in CLI if you index using the provided command.

## Index application folders

Raw disk sources without `allow_unregistered => true` are hidden, including
previously indexed rows and their content URLs. Use a model source for folders
that mix originals and conversions.

Register explicit existing paths. Studio stores references; it does not copy files
or scan an entire bucket during browsing:

```sh
php artisan media-studio:application-files:index avatars user 42 \
  --path=avatars/team/alice.png --path=avatars/team/bob.png
```

The principal type must be registered in `media-studio.principals`. The actor needs
the department, source and indexing permissions. One call accepts up to 120 paths.
An inventory producer can submit successive bounded batches. Relative folders such
as `team` are preserved. Paths outside the configured prefix are rejected.

Application code can use the same service after the owning application commits its
upload or replacement:

```php
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\Identity\Principal;

$actor = Principal::fromUser(auth()->user());
app(ApplicationCatalog::class)->indexDisk($actor, 'avatars', [$storedPath]);
```

When your application's authoritative deletion completes, invalidate its catalog
reference:

```php
app(ApplicationCatalog::class)->forgetDisk($actor, 'avatars', $deletedPath);
```

`forgetDisk()` never deletes the underlying object. Call it only from trusted
application code after your own reference/ownership rules have permitted deletion.
This integration does not assume that a FileUpload field automatically deletes
persisted files. Register lifecycle hooks in the application or plugin that actually
owns those files.

## Register originals and variants on your own models

[Read Register model originals and renditions](/application-files-models#register-originals-and-variants-on-your-own-models).

## Spatie models and collections

[Read Register model originals and renditions](/application-files-models#spatie-models-and-collections).

## Tenanted applications

[Read Authorize application file sources](/application-files-access#tenanted-applications).

## Browser behavior and performance

The HTTP transport exposes Application files in the Library navigation when the
department is enabled and authorized. Its page uses the same grid, large-grid, list and fullscreen preview as the
workspace library. It has no workspace selector, upload action or workspace member
controls. The existing navigation remains mounted during view changes. Both Spatie and application folders share one listing. The Source filter selects
all sources or a specific configured source. Folder and source selection are stored
in the URL. There are no separate source tabs. The list uses keyset pagination, virtual rows and a bounded window of
six pages (normally 360 rows). Originals are fetched only for an explicit preview
or download. Without a thumbnail conversion, list items show an icon.

The database index starts with tenant, source, source revision and parent, followed
by deterministic name/ID ordering. Browsing does not count the full catalog or
read filesystem metadata. Original delivery checks the configured source and fresh
disk metadata; a changed/deleted object needs refresh or invalidation.

When this feature is enabled, legacy workspace source projections are hidden from
ordinary workspace listings. Existing database rows and originals are preserved;
they are not silently moved into the global catalog. Configure and index the global
sources explicitly. Studio-managed workspace files keep their existing lifecycle.

## Access to individual model sources

[Read Authorize application file sources](/application-files-access#access-to-individual-model-sources).

### Show these permissions in Shield

[Read Authorize application file sources](/application-files-access#show-these-permissions-in-shield).

## Generate missing thumbnails on demand

[Read Generate thumbnails for application files](/application-files-thumbnails#generate-missing-thumbnails-on-demand).

### Cache limits and cleanup

[Read Generate thumbnails for application files](/application-files-thumbnails#cache-limits-and-cleanup).

### Prepare a batch without a queue worker

[Read Generate thumbnails for application files](/application-files-thumbnails#prepare-a-batch-without-a-queue-worker).
