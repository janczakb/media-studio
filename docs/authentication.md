---
title: "Host and standalone authentication"
description: "Configure the authenticated principal, host guards, Filament and standalone accounts."
---

Studio uses the authenticated user and its configured principal alias to resolve workspace permissions.

## Authentication: host model, Filament and standalone installation

Studio's backend requires a Laravel `Authenticatable` principal registered by
class alias in `principals`. It does not require `App\Models\User` or a particular
table name. Existing models, custom models and custom guards are supported.
Map profile attributes with `member_profiles.fields` or implement
`UseFlex\MediaStudio\Identity\MemberProfileResolver`.

For an existing application, reuse its guard and register its model:

```php
'principals' => ['user' => App\Models\Account::class],
'member_profiles' => [
    'resolver' => UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver::class,
    'fields' => ['name' => 'display_name', 'email' => 'email', 'avatar_url' => 'photo_url'],
],
```

For Filament, register the panel's actual user model and use the optional
`UseFlex\MediaStudio\Integrations\Filament\FilamentMemberProfileResolver`. The
adapter uses the panel guard, Filament user name and avatar contracts. Core
Studio does not import Filament to perform ordinary authentication.

For a standalone Laravel installation with no existing users, create a user model
and an authentication flow in that installation, then register it the same way.
This checkout's standalone example has an independent `App\DemoUser` and a local,
loopback-only demo guard. That guard is for local preview, not public deployment.
The optional Fortify-based account module now supplies independent accounts,
login, verification, password reset and TOTP setup. See
[Standalone accounts and SMTP](standalone-accounts.md) for opt-in dependencies,
migrations, mail selection and tenant boundaries. Do not reuse the demo
identity to turn a public installation into single-user mode.
