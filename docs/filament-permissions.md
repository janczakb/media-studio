---
title: "Filament role permissions"
description: "Configure Spatie roles and optional Guardian or Shield integration."
---

Studio checks Spatie Permission 8 and workspace membership. Register definitions
for the same guard used by your Filament panel:

```sh
php artisan media-studio:permissions --guard=web
```

The command registers definitions. Assign only the operations each application
role needs. See [workspace roles and grants](/members#configure-spatie).
For example, grant browsing and downloads to a dedicated role:

```php
use Spatie\Permission\Models\Role;

$role = Role::findOrCreate('Studio Viewer', 'web');
$role->givePermissionTo(['studio.view', 'studio.files.download']);
```

Studio works without Filament, Guardian or Shield. **Guardian is a user interface for
editing roles, not Studio's authorization engine.** Install it only if your application
uses that role editor. Guardian **1.15 or later** supports Studio's required Spatie 8.

When the role editor and Studio are installed in the **same Laravel application**, Studio
merges its permission labels into the editor's `custom_permissions` configuration after
providers have booted. Existing application labels take precedence. This registration
performs no database queries or writes; run the installer above to create the permissions.
In Guardian, these permissions appear in **Custom permissions**. Enable that tab if your
panel has hidden it. English and Polish Guardian labels follow the current request locale;
application translation overrides remain authoritative.

To give Studio its own **Media Studio** tab in a Guardian 1.15 role form,
extend Guardian's role form in your application and apply Studio's adapter:

```php
use Filament\Schemas\Components\Section;
use UseFlex\MediaStudio\Integrations\Filament\Guardian\GroupedRolePermissions;
use Waguilar\FilamentGuardian\Base\Roles\Schemas\BaseRoleForm;
use Waguilar\FilamentGuardian\FilamentGuardianPlugin;

class RoleForm extends BaseRoleForm
{
    protected static function buildPermissionsSection(FilamentGuardianPlugin $plugin): Section
    {
        $section = parent::buildPermissionsSection($plugin);

        return $section->schema(GroupedRolePermissions::apply($section->getDefaultChildComponents()));
    }
}
```

Use this form from your application's role resource. The tab groups installed
Studio permissions that Guardian classifies as custom for the current guard.
Each group supports bulk selection. Guardian still handles saving the role and
enforces its configured grant restrictions. Upstream plugin files need no edits.

Disable either optional adapter independently:

```php
// Merge this fragment into config/media-studio.php.
return [
    'permissions' => [
        'abilities' => ['view' => 'company.media.browse'],
        'labels' => ['company.media.browse' => 'Company media library'],
        'editors' => ['guardian' => false, 'shield' => false],
    ],
];
```

The flags control editor registration only; they never disable server authorization.
For Shield, application-file permissions retain the existing opt-in
`application_files.shield` flag. Other Studio operation labels use
`permissions.editors.shield`.

A role editor in a separate application or database does not automatically manage the
standalone Studio installation. Install Studio in that application and configure the same
principal/guard and permission storage intentionally, or manage the standalone application's
roles directly. Studio does not synchronize role records across independent installations.
