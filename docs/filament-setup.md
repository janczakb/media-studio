---
title: "Set up a Filament panel"
description: "Install Studio in a Laravel panel, serve assets and verify private media delivery."
---

## Install and authorize

1. [Install Studio](/installation) in the Laravel application that serves the
   panel and run the Media Studio migrations. Use a supported Filament 5
   installation. FLEX Fields is optional for FLEX-specific controls.
2. Register the panel's authenticatable model in `media-studio.principals`.
   Register permission definitions for the panel's guard with
   `php artisan media-studio:permissions --guard=web`, substituting your
   actual guard. Grant the operations your panel uses and assign Studio
   workspace membership. [Roles and permission editors](/filament-permissions)
   explains Spatie, Guardian and Shield.
3. For a tenant panel, configure
   [Filament tenancy](/filament-tenancy) before adding the first media field.
   Studio routes, thumbnails and downloads must resolve the same tenant.

Permission registration creates definitions. It does not grant roles or
workspace access. Continue to authorize the host record through Filament's
normal resource or action policy.

To reuse names and avatars from your panel, configure
`member_profiles.resolver` with `FilamentMemberProfileResolver::class`.
Provide an active or default Filament panel for the avatar provider.
See [profile configuration](/configuration#workspace-member-profiles).

## Serve the matching browser build

Publish the matching prebuilt browser assets; no Node.js build is needed:

```bash
php artisan vendor:publish --tag=media-studio-assets --force
```

The default public URLs are `/vendor/media-studio/studio.js` and `/vendor/media-studio/studio.css`. Republish these assets when upgrading Studio.

Serve the contents of Studio's built React distribution from the application.
Keep the emitted entry files and their relative chunks together. A field's
`moduleUrl` points to `studio.js`. Set `stylesheetUrl` to
`studio.css`. Keep `studio-mark.svg` with the build. Native RichEditor also
loads `rich-editor-extension.js` beside `studio.js`.

Publish Filament's native assets using the application's existing process.
If using FLEX Fields, make its matching component assets available through
its existing asset publisher. The workspace picker loads the Studio module
when the modal opens. Private file requests use the host application's
authenticated routes and should not be served as public static files.

## Add a first field

Use a server-resolved workspace that the current actor can access. This
example selects one existing image and displays the stored reference on an
infolist. `$workspaceId` is supplied by your application.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaEntry;
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker;

$coverField = StudioMediaPicker::make('cover')
    ->studio($workspaceId, '/studio-assets/studio.js', '/studio-assets/studio.css')
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->allowUpload(false);

$coverEntry = StudioMediaEntry::make('cover')
    ->studio($workspaceId)
    ->openStudioMedia();
```

Store a single `assetId:versionId` reference in the model's string column.
For a multiple field, store the ordered references in a JSON column with an
array cast. Register usages in the same transaction as the owner save. See
[the reference lifecycle](/filament-media-references).

## Verify your application

- Open the modal as an authorized member. Select a file, save the record,
  then reopen it and verify the pinned version. Check that a different
  tenant or unauthorized member cannot access the same URL.
- If enabling upload, test the configured upload destination, the MIME/size
  limits and storage access from the Laravel worker. Verify that a valid
  rendition reaches ready state and the field displays the intended preset.
- If enabling a text editor, insert an image, save and reopen the record,
  and verify the renderer with the intended audience. Authenticated version
  URLs require Studio access when rendered.
- If the modal fails to open, check that the module, CSS and browser chunks
  serve the expected content and that authenticated API requests reach the
  matching host or tenant routes. When only thumbnails are missing, verify
  the selected version and preset rather than loading originals as previews.

See [the configuration reference](/filament-configuration) for all supported
Studio-specific field options.
