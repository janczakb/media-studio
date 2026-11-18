---
title: "Use a separate media library for each tenant"
---

Studio can isolate workspaces by your application's active tenant. Members still
need workspace membership. A user who belongs to two companies sees only the
workspaces for the company currently selected.

Tenant identities use a registered model alias and the model's unchanged key.
UUID, ULID and string keys are supported. Two model types with the same key have
different identities.

## Filament panels

See [Filament tenancy](/filament-tenancy) for panel configuration, examples and access checks.

## Other Laravel applications

Provide your own tenant resolver when Studio runs outside Filament. Register
the resolver in the Laravel container and put its class in
`tenancy.resolver`.

```php
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class CompanyTenantResolver implements TenantResolver
{
    public function current(Principal $principal): ?TenantIdentity
    {
        // Your middleware establishes this context after authenticating the user.
        $company = app(CurrentCompany::class)->get();

        return $company
            ? new TenantIdentity('company', (string) $company->getKey())
            : null;
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        return $tenant->type === 'company'
            && $principal->type === 'user'
            && \App\Models\User::query()
                ->whereKey($principal->id)
                ->whereHas('companies', fn ($query) => $query->whereKey($tenant->id))
                ->exists();
    }
}
```

`CurrentCompany` in this example is your application's existing context
service. It must obtain the active company from authenticated routing or a
validated session. `allows()` must also work in a queue worker.

With a configured resolver, a missing or unauthorized tenant returns HTTP 404.
Changing the active tenant cannot grant access to another tenant's workspace,
content, references or upload sessions. The application remains responsible for
its model relationships, tenant selection and workspace memberships.
