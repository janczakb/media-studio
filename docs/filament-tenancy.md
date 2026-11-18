---
title: "Filament tenancy"
description: "Register Studio routes in an authenticated tenant panel."
---

Workspace access still requires Studio membership. See [tenancy concepts](/tenancy).

Configure your tenant and principal models, then register Studio's tenant plugin
in the panel. The panel must already use Filament's native tenancy.

```php
// config/media-studio.php
use App\Models\Company;
use App\Models\User;
use UseFlex\MediaStudio\Integrations\Filament\FilamentTenantApiUrl;
use UseFlex\MediaStudio\Integrations\Filament\FilamentTenantResolver;

return [
    'routes' => [
        'enabled' => false,
        'prefix' => 'media-studio/api/v1',
        'base_url_resolver' => FilamentTenantApiUrl::class,
    ],
    'principals' => ['user' => User::class],
    'tenancy' => [
        'resolver' => FilamentTenantResolver::class,
        'models' => ['company' => Company::class],
    ],
    // Keep your storage, upload and processing settings here.
];
```

```php
use App\Models\Company;
use Filament\Panel;
use UseFlex\MediaStudio\Integrations\Filament\TenantStudioPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->tenant(Company::class, slugAttribute: 'slug')
        ->plugin(TenantStudioPlugin::make());
}
```

The plugin registers Studio's API inside the authenticated tenant routes.
Filament checks tenant membership before Studio checks workspace access. The
picker and generated file, thumbnail and conversion addresses use the panel's
tenant route. The API uses the panel's configured authentication guard.

Implement Filament's `HasTenants` contract on the principal model, including
`canAccessTenant()`. Studio calls this method again from a fresh database model
when processing queued image conversions. Keep that check usable outside an HTTP
request. Resolve access from persisted relationships and policies.

A workspace records its tenant when it is created. A client-supplied tenant ID
cannot change that assignment. Existing workspaces created before tenant
isolation have an empty tenant identity and remain in the standalone library.
Assign them through a reviewed application migration before including them in a
tenant's library.

### Native actions and Livewire

Use the same tenant plugin on pages hosting Studio's picker or native actions.
The plugin makes its tenant middleware persistent. Studio validates the origin
of each verified Livewire snapshot against the established tenant context.
A component from another page cannot inherit a previous component's tenant
context in a batched request.

### Background work

A conversion job stores the tenant alias and key with the workspace, source
version and actor. The worker rechecks tenant and workspace access before claiming
and publishing output. Removing a member's tenant access blocks later publication.
Administrative upload cleanup remains scoped to the exact stored staging object
and accepted version.
