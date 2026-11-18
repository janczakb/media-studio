---
title: "Workspace and viewer settings"
description: "Configure workspace availability, limits, language, time zone, profile data and deep links."
---

Workspace limits and authorization are evaluated by Laravel. Browser preferences are scoped to the signed-in identity.

## Workspace availability and limits

```php
'workspaces' => ['enabled' => true, 'max_owned' => 10],
'workspace_creation' => true,
```

Creation requires both flags and the `workspaces.create` permission. The owned
workspace limit is enforced transactionally per principal and tenant; membership
in someone else's workspace does not consume that limit. Set `enabled` to `false`
to expose only the tenant's default workspace, including at the API boundary.
This hides workspace switching and creation in the account menu and pickers.
Existing other workspaces and their files are retained, not deleted.

The account menu replaces the old workspace sidebar section. Owners with
`workspaces.manage` can rename their workspace and select its default through
Workspace settings. The settings dialog supports resumable transfer or removal of non-default
workspaces. See [Workspace removal](workspace-retirement.md) for queue requirements,
confirmation and external-source boundaries. The legacy DELETE API remains empty-only.
Provide React `StudioOptions.onLogout` to display a Logout action using your
host application's sign-out flow.

## Language and timezone preferences

Studio settings persist the selected language and timezone preference in the
current browser, scoped to the supplied user identity. Automatic timezone is on
by default and follows the browser's IANA timezone. Turning it off reveals a
searchable, grouped, virtualized HeroUI timezone selector. Dates are stored in UTC;
the preference changes display and date-picker interpretation, not stored data.

## Settings and workspace permissions

Open **Settings** from the account menu. The sidebar separates personal
preferences from workspace policy, in this order:

- **General**: language and automatic or manually selected timezone.
- **Appearance**: light or dark theme.
- **Keyboard shortcuts**: Mac/Windows keycaps, recording, conflicts and reset.
- **Notifications**: transfer notices, browser push and subscribed event types.
- **Uploads**: workspace file-type restrictions and resumable-transfer limits.
- **Processing**: read-only conversion presets and video processing policy.
- **Existing sources**: incremental imports, when the adapter and access allow them.

Personal preferences are stored in the current browser. Browser push subscriptions
and event choices use the notification API. Workspace upload settings are saved
through the Laravel API and apply after reopening the library. Unsupported or
unauthorized workspace sections are omitted. Processing is configured on the
server; this dialog does not change conversion recipes. A failed settings save
keeps the draft available for retry instead of reloading over it.

Viewing settings requires `studio.settings.view`; changing workspace upload settings requires `studio.settings.manage` and an owner/editor membership. Assign these permissions using Spatie Laravel Permission. Configure alternative names in `permissions.abilities`, as shown below. Workspace ownership alone does not grant these permissions.

Uploads shows the server-allowed extensions grouped by file category. Selected
extensions narrow the global policy. Deselect every extension to reject new
uploads. Chunked uploads additionally require the server setting to be enabled.
The optional workspace size limit can only reduce the server's chunked file limit.

Processing displays the configured image presets, format, quality and metadata
policy, plus the available video processing settings. Configure processing
presets in Laravel's `media-studio.php` configuration file.

## Restore a library view from its URL

Enable URL synchronization for a standalone Studio instance.

```ts
mountMediaStudio(container, {
    transport,
    syncUrl: true,
});
```

Studio writes the workspace, library view, folder and fullscreen file to the
current URL. Photos also records the selected media filter and timeline scale.
Reloading the URL restores those choices using authenticated requests. Browser
Back and Forward restore the recorded locations. Unrelated application query
parameters are preserved.

Embedded pickers keep their own selection flow and do not synchronize the host URL.

## Workspace member profiles

Studio reads member names, email addresses and avatar URLs from the registered principal model. Configure the attribute paths in `config/media-studio.php`; set a field to `null` to omit it:

```php
'member_profiles' => [
    'resolver' => \UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver::class,
    'fields' => [
        'name' => 'display_name',
        'email' => 'email',
        'avatar_url' => 'profile_photo_url',
    ],
],
```

To use Filament's user name and avatar providers, install Filament and configure the optional adapter:

```php
'member_profiles' => [
    'resolver' => \UseFlex\MediaStudio\Integrations\Filament\FilamentMemberProfileResolver::class,
    'fields' => ['email' => 'email'],
],
```

The Filament adapter uses the active or default Filament panel's avatar provider, including a user's `HasAvatar` implementation. Configure the default panel when Studio's API runs outside a panel request. For another identity system, implement `MemberProfileResolver` and return `name`, `email`, and `avatarUrl` for the supplied authenticated model. Return `null` for values that should not be shown. Workspace membership is checked before profiles are returned.

### Member permissions and Redis

Studio checks all private operations through Spatie Laravel Permission and Laravel's Gate. Add Spatie's `HasRoles` trait to your authenticatable model, run Spatie's published migration, and install Studio's permission definitions:

```sh
php artisan media-studio:permissions --guard=web
```

The command creates permission definitions only. Assign them explicitly to your application's roles or users:

```php
$role->givePermissionTo([
    'studio.view',
    'studio.members.view',
    'studio.members.invite',
    'studio.members.manage',
]);
$user->assignRole($role);
```

Viewing, inviting and managing people are separate permissions. Workspace membership and tenant isolation remain required. A workspace role alone never bypasses permission checks. Public share links delegate only the operations explicitly allowed by that link; recipients do not need an account.

To use your existing permission names, configure the operation map:

```php
'permissions' => [
    'abilities' => [
        'members.view' => 'media.people.browse',
        'members.manage' => 'media.people.manage',
        'members.invite' => 'media.people.invite',
        'settings.view' => 'media.settings.browse',
        'settings.manage' => 'media.settings.manage',
    ],
],
```

Unmapped operations use the `studio.` prefix. Empty permission names are rejected. Background processing reloads the actor and checks access again before publishing its result. Configure `permissions.actor_resolver` when using a custom non-Eloquent identity provider.

Spatie controls its own permission cache. To use a configured Laravel Redis store:

```php
// config/permission.php
'cache' => [
    'expiration_time' => \DateInterval::createFromDateString('24 hours'),
    'key' => 'spatie.permission.cache',
    'store' => 'redis',
],
```

Set a unique Laravel cache prefix for applications sharing Redis. Use Spatie's `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `assignRole` and `syncRoles` methods instead of writing directly to its tables. Role and permission definitions are cached; user assignments also have in-memory relationship state. Reload user relationships when retaining the same model across permission changes.

Studio does not persist an additional cache of member authorization decisions. Every member request checks workspace access and the configured Gate permission again. Studio's `media-studio.cache.store` setting is separate from Spatie's `permission.cache.store`.

For Octane, Spatie provides `register_octane_reset_listener` when reset-cycle cleanup is needed. Applications switching tenant cache prefixes or stores within one process must reinitialize `PermissionRegistrar` after switching context, as described in [Spatie's cache documentation](https://spatie.be/docs/laravel-permission/v8/advanced-usage/cache). Do not reset the whole permission cache on every Studio request.
