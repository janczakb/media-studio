---
title: "Your default media library"
---

Studio is ready to use on first opening: it creates **Media library** automatically. Users do not need to create a workspace before uploading files. Uploading still requires the appropriate Spatie permissions.

With tenancy enabled, each tenant has its own default workspace. Without tenancy, the application has one default. Additional workspaces remain optional and require `studio.workspaces.create` plus `workspace_creation` configuration.

## Configuration

```php
// config/media-studio.php
'default_workspace' => [
    'enabled' => true,
    'auto_join' => true,
    'name' => 'Company media',
],
'workspace_creation' => false,
```

The configured name is used when creating a default; changing it later does not overwrite an existing name. An authorized owner can change its name and emoji from **Account menu → Workspaces · Settings**.

### Who receives access?

Automatic joining applies only to the default workspace and authenticated users authorized by `studio.view` in the current tenant. It does not make files public or add users to private additional workspaces. Such users share this default library, so give `studio.view` only to users who should enter it.

The initial membership level follows existing Spatie authorization: users allowed to manage members receive Owner; users with write access receive Member; others receive Viewer. No Spatie role or permission is assigned automatically. All file operations retain their separate permission checks.

Removing a membership is persistent: reopening Studio does not automatically add that user back. An authorized person must explicitly restore their membership. Revoking Spatie permissions also continues to block the corresponding operations.

If your application manages membership itself, disable automatic joining:

```php
'default_workspace' => [
    'enabled' => true,
    'auto_join' => false,
    'name' => 'Company media',
],
```

To manage creation and membership entirely yourself, set `enabled` to `false`.

## Rename or change the default

An owner with `studio.workspaces.manage` can use the workspace's **…** menu to rename it or choose **Set as default** on another workspace in the same tenant. The current default appears first on a fresh opening without an explicit workspace URL. Explicit links keep opening their selected workspace.

Choosing a different default changes which workspace eligible users automatically join. Use this for a shared library, not for a private project that should remain invitation-only.

The current default is protected by a database foreign key against deletion. Choosing another default releases that protection on the previous workspace. Workspace settings supports a confirmed move-or-delete workflow; see [workspace retirement](workspace-retirement.md).

Existing private workspaces are not automatically converted into a shared default. The automatic default starts as a separate empty library.

## API

```http
GET /media-studio/api/v1/workspaces
PATCH /media-studio/api/v1/workspaces/{workspace}
Content-Type: application/json

{"name":"Company media"}
```

```http
POST /media-studio/api/v1/workspaces/{workspace}/make-default
```

Workspace responses include `isDefault`. The React transport exposes optional `updateWorkspace` and `makeDefaultWorkspace` methods; custom transports can implement them to enable the corresponding sidebar controls.

## Create and personalize a workspace

The full-screen creator walks through purpose, name/icon, access and review.
Nothing is created until the final confirmation. Team invitations are optional
and sent with a separate explicit action after creation (up to ten per wizard).
Creating a team does not automatically admit everyone in the host tenant.
Personal workspaces have one member and cannot accept workspace invitations or
become the shared default. Individual file sharing remains separately authorized.
Existing workspaces keep their current memberships and use the team type.

Run package migrations to add the nullable `emoji` and `kind` columns. Emoji
selection uses native glyphs, localized search, categories, skin tones and a
virtualized list. The independently built `workspace-emoji.js` is requested only
when the picker opens. Serve that asset alongside `studio.js`; there are no emoji
image/CDN requests. Clear the icon to restore the folder.

Native discovery advertises `workspaceIdentity: true`. Custom transports can
opt into the identity screens with this flag once they persist the optional
third argument to `createWorkspace(name, signal, identity)`. Older transports
keep the name-only contract and do not offer unsupported personal/emoji controls.
Workspace responses use `workspaceType` (personal/team), distinct from library
scope kind. Updating an icon uses the optional fourth argument of
`updateWorkspace(id, name, signal, {emoji})`.

```http
POST /media-studio/api/v1/workspaces
Content-Type: application/json

{"name":"Design","kind":"personal","emoji":"🎨","requestKey":"0619f0d3-e5af-4e78-a0ab-198910a8d400"}
```

A UUID request key makes retries return the same workspace, including after a
response is lost. Reusing it with different creation fields returns 409. Owner
limits, permissions and tenant boundaries are checked on the server.

Workspace names must contain 3–15 Unicode characters after trimming leading and trailing spaces. The limit applies to creation and renaming, including API calls; existing names are not changed automatically.

## Lazy language loading

[Read Localize the Studio interface](/localization#lazy-language-loading).
