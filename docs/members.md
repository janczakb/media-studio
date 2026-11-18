---
title: "Members and invitations"
---

Manage workspace access from **Members**. The page has three tabs: **Members**, **Add people**, and **Invitations**. Users without invitation permission see the member list only. Users without member-view permission cannot open the page or retrieve its API data.

## Access levels

| Level | Workspace boundary |
| --- | --- |
| Owner | Protected workspace owner. Can assign or remove administrators when authorized by Spatie. |
| Admin | Can organize files and manage access when authorized. Cannot change the owner or other administrators. |
| Member | Can upload and organize files when authorized. |
| Viewer | Can browse files. Downloading requires its own permission. |

Every operation also requires its Spatie permission. A workspace level never grants a global application permission. This prevents adding someone to a workspace from silently changing their access elsewhere in your application. The Member level uses the API value `editor`; the other values are `owner`, `admin`, and `viewer`. Owner assignment is deliberately excluded from ordinary member editing and invitations.

## Configure Spatie

Studio requires **Spatie Laravel Permission 8**. Add `Spatie\Permission\Traits\HasRoles` to your authenticatable model and install Spatie's database tables. Then create Studio's permission definitions for your guard:

```sh
php artisan media-studio:permissions --guard=web
```

The command is idempotent and uses Spatie's configured permission model and connection.
It creates definitions for the specified configured authentication guard, including enabled
application-file sources. It does not assign permissions or roles to anyone, delete existing
permissions, or change workspace membership. Definitions are written in a transaction and
Spatie's permission cache is cleared after success. Run it again when adding sources or
changing ability mappings. The role's guard must match the guard used by the installer.

### Optional Filament role editors

See [Filament role permissions](/filament-permissions) for panel configuration, examples and access checks.

Create application roles appropriate for your product. For example:

```php
use Spatie\Permission\Models\Role;

$viewer = Role::findOrCreate('Media viewer', 'web');
$viewer->syncPermissions(['studio.view', 'studio.files.download']);

$manager = Role::findOrCreate('Media access manager', 'web');
$manager->syncPermissions([
    'studio.view',
    'studio.members.view',
    'studio.members.invite',
    'studio.members.manage',
]);

$user->assignRole('Media access manager');
```

Assign only the application permissions the person should have. A person with `studio.members.view` can browse the list without being allowed to invite, change roles or remove people. Giving a global permission does not bypass workspace membership or tenant isolation. See [configuration](/configuration#member-permissions-and-redis) for custom permission names and Redis.

## Search your application's users

Provide a scoped directory query. Studio does not expose your entire user database by default.

```php
namespace App\Media;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use UseFlex\MediaStudio\Members\MemberDirectoryScope;

final class MemberDirectory implements MemberDirectoryScope
{
    public function query(Authenticatable $actor, object $workspace): Builder
    {
        return User::query()
            ->where('organization_id', $actor->organization_id)
            ->whereKeyNot($actor->getAuthIdentifier());
    }
}
```

Register the model and directory:

```php
// config/media-studio.php
'principals' => ['user' => App\Models\User::class],
'members' => [
    'directory' => App\Media\MemberDirectory::class,
    'search_attribute' => 'name',
    'email_attribute' => 'email',
    'invitation_days' => 7,
    'accept_redirect' => '/media',
],
```

Search is debounced, prefix-based and limited to 20 candidates. Use an index appropriate for your database and directory scope. Assignment rechecks the same scoped query on the server. Custom names, email fields and avatar providers are described in [member profiles](/configuration).

## Invite by email

Configure Laravel's mail transport and your application's authentication and email-verification flow. Recipients must sign in with the invited, verified email address. Studio does not create accounts or bypass your registration rules.

Invitations expire after the configured number of days (1–30). Sending a new invitation for the same workspace and address rotates the secret link; the previous link stops working. Only the token hash is stored in the invitation table. Accepted invitations cannot be reused. Revoked, expired and wrong-recipient links are rejected. Acceptance rechecks the inviter's current membership, tenant and permission; an administrator invitation additionally requires the inviter to remain the owner.

The Invitations tab shows pending, accepted, expired and revoked invitations. Authorized users can revoke an unused invitation. The local example uses Laravel's `log` mail transport and does not deliver external email. Configure SMTP or your preferred Laravel mail transport for actual delivery.

## Large member lists

Member and invitation lists use cursor pagination and TanStack virtualization. Profile payloads are retained for at most eight pages, with 25 records per page. Earlier payloads are fetched again when scrolling back. Only rendered rows have controls in the document. No full-directory count is needed to open the page.
