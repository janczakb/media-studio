---
title: "Authorize application file sources"
description: "Scope model and folder sources to tenants and authorized viewers."
---

The host application owns the files and its access rules. Choose the correct source scope before indexing a shared or tenant-owned disk.

## Tenanted applications

Global means **outside workspaces**, not outside tenant security. Configure the
existing `TenantResolver`; it must resolve and authorize the current tenant without
trusting browser query parameters. The catalog stores a separate tenant scope.
Your source must also isolate the actual storage or media rows:

```php
// Tenant-specific disk prefix:
'prefix' => static fn (TenantIdentity $tenant): string
    => 'tenants/'.$tenant->id.'/avatars',

// Example Spatie model scope; adapt to your actual schema:
'scope' => static fn (Builder $query, TenantIdentity $tenant, Principal $actor)
    => $query->whereHasMorph('model', [Post::class],
        static fn (Builder $posts) => $posts->where('tenant_id', $tenant->id)),
```

The CLI must establish its tenant through your resolver too. A missing or
unauthorized tenant fails closed. Do not use an unscoped Spatie query in a
multi-tenant application.

## Access to individual model sources

Studio uses Laravel's Gate and the same Spatie Permission roles used by Shield.
Each enabled registered source receives its own permission. Source keys remain
stable when a model or its display label is renamed.

~~~php
'application_files' => [
    'enabled' => true,
    'sources' => [
        'posts' => [
            'type' => 'model',
            'model' => App\Models\Post::class,
            'label' => 'Editorial images',
            'revision' => 'v1',
            'stores' => ['s3' => 'posts'],
            'scope' => fn ($query, $tenant, $principal) => $query
                ->where('organization_id', $tenant->id),
        ],
        'sales' => [
            'type' => 'model',
            'model' => App\Models\Sale::class,
            'label' => 'Sales attachments',
            'revision' => 'v1',
            'stores' => ['s3' => 'sales'],
            'scope' => fn ($query, $tenant, $principal) => $query
                ->where('organization_id', $tenant->id),
        ],
    ],
],
~~~

Both models implement RegistersApplicationMedia as shown above. The scopes in
this example assume the application has configured Studio's tenant resolver.

Create permission definitions after registering sources.

~~~sh
php artisan media-studio:permissions --guard=web
~~~

Assign the department permission and the relevant sources through your role editor
or the configured Spatie role model in application setup.

~~~php
$editorRole->givePermissionTo([
    'studio.application-files.view',
    'studio.application-files.source.posts',
]);
~~~

This role can open Application files and see Posts. Granting
studio.application-files.source.sales additionally enables Sales. A source's
explicit permission setting overrides its generated key. Empty permission names
deny access.

Spatie catalog sources additionally require studio.application-files.spatie.view,
alongside their own source permission and the department permission. A Spatie
source can restrict its scope to one owner model. Register several scoped Spatie
sources when different roles need access to different owner models.

Checks apply to discovery, listings, details, neighboring files, generated
thumbnails and original delivery. The configured query scope further limits
which records a permitted user can see.

### Show these permissions in Shield

Shield discovers its Filament resources, pages and widgets. Studio's registered
sources use its custom-permissions facility. Enable the bridge in Studio.

~~~php
// config/media-studio.php
'application_files' => [
    'enabled' => true,
    'shield' => true,
    'sources' => [
        // Your registered sources.
    ],
],
~~~

Keep Studio's exact keys in Shield and enable its custom-permissions tab.

~~~php
// Relevant options in config/filament-shield.php
'permissions' => [
    // Preserve your other permission settings.
    'format_custom_permission_keys' => false,
],
'shield_resource' => [
    // Preserve your other resource settings.
    'tabs' => [
        // Preserve your other tab settings.
        'custom_permissions' => true,
    ],
],
~~~

The bridge merges the generated definitions into custom_permissions, preserving
existing keys and labels. It runs when Shield configuration is available. Run the
Studio permission command after adding or renaming a source. The command creates
definitions using Spatie's configured permission model and guard. Role assignments
remain under the application's control.

Disabling custom-key formatting applies to all Shield custom permissions.
Applications with their own permission-key builder can instead preserve Studio
keys inside that existing builder. The definitions are also available directly.

~~~php
use UseFlex\MediaStudio\ApplicationFiles\ApplicationFilePermissions;

$permissionLabels = ApplicationFilePermissions::definitions();
~~~
