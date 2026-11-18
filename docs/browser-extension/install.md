---
title: "Install and connect the extension"
description: "Set up the server integration, load Chrome or Firefox builds and approve a workspace."
---

This guide covers the development builds present in the repository. You need a reachable Media Studio installation, an account allowed to upload into the destination workspace, and a compatible browser.

## Enable the server integration

From the Laravel host, publish the optional extension migration and run migrations.

```bash
php artisan vendor:publish --tag=media-studio-extension-migrations
php artisan migrate
```

Set the environment values to your installation's actual Studio interface address.

```dotenv
MEDIA_STUDIO_EXTENSION_ENABLED=true
MEDIA_STUDIO_EXTENSION_STUDIO_URL=https://studio.example.com/studio
```

The Studio URL must open the interface that can approve browser connections. For an installation whose interface lives at the origin root, use that root URL. The extension API prefix is configured separately.

```php
// Under integrations in config/media-studio.php.
'browser_extension' => [
    'enabled' => env('MEDIA_STUDIO_EXTENSION_ENABLED', false),
    'path' => 'media-studio/extension/v1',
    'studio_url' => env('MEDIA_STUDIO_EXTENSION_STUDIO_URL', env('APP_URL')),
    'token_days' => 30,
],
```

Apply configuration changes through your application's normal deployment process, including its configuration cache. For multiple app instances, use shared database and cache services so approval, access checks and rate limits work across processes.

## Prepare the account

| Operation | Default permission |
| --- | --- |
| Open Studio and enter the workspace | `studio.view` and workspace access |
| Approve and use an extension connection | `studio.extension.use` |
| Upload new files | `studio.uploads.create` |
| Save URL or selected text as a note | `studio.notes.create` |
| Create a destination folder | `studio.folders.create` |
| Apply capture tags | `studio.tags.manage` |

Studio also requires write access to the destination workspace. Install the package's permission definitions and assign the needed abilities through your existing roles. [Permissions and roles](/filament-permissions)

## Load a build

With the checkout's dependencies installed, build the extension from the repository root.

```bash
npm --prefix packages/browser-extension run build
```

| Browser | Development installation |
| --- | --- |
| Chrome 120+ | Open `chrome://extensions`, enable **Developer mode**, choose **Load unpacked** and select `packages/browser-extension/dist/chrome`. |
| Firefox 140+ | Open `about:debugging#/runtime/this-firefox`, choose **Load Temporary Add-on**, and select `packages/browser-extension/dist/firefox/manifest.json`. Reload the temporary add-on after a browser restart. |

Pin the extension icon if you want quick access to its popup. After rebuilding, reload the installed extension in the browser's extension manager and refresh source pages where it was already active.

### Install for Chrome

In Chrome 120 or later, load `dist/chrome` through **chrome://extensions → Developer mode → Load unpacked**. Pin FLEX Capture in the browser toolbar, then follow workspace approval below.

### Install for Firefox

In Firefox 140 or later, select `dist/firefox/manifest.json` through **about:debugging → This Firefox → Load Temporary Add-on**. Reload the temporary extension after a browser restart, then approve its workspace below.

## Approve a workspace

1. Open the extension popup, then **Preferences → Websites and connections → Connect Studio**. A disconnected save surface also directs you to this popup.
2. Enter the installation address. Grant the browser access to that Studio host when requested.
3. The extension opens Studio's approval page. Sign in with your normal Studio account.
4. Compare the eight-character code in Studio with the extension's code, choose the destination workspace and approve it.
5. Return to the popup and confirm the connection has completed. In Batch save, check the destination and save one allowed test file.

The approval request expires after ten minutes. Start a new connection if it expires or has already been used. The default token lifetime is 30 days, configurable from 1 to 365 days. Approval authorizes the initiating account and workspace. Current permissions are checked on subsequent requests.

## Discovery and custom paths

When given an installation URL, the extension discovers the API through `/.well-known/flex-media-studio.json` on that origin. The returned approval URL and API base must stay on that origin.

If discovery is unavailable, the current client can accept the complete extension endpoint when its path ends in `/extension/v1`. For example, `https://studio.example.com/media-studio/extension/v1`. With a customized endpoint suffix, keep well-known discovery reachable. Production hosts use HTTPS. Localhost, loopback and .test development hosts can use HTTP.

```bash
php artisan route:list --path=extension
```

## Connect another workspace or revoke a device

Repeat Connect Studio and approve the additional workspace. The save surface can then offer those connections when workspace switching is enabled. An existing approval does not automatically grant access to another workspace.

Use the browser integration in Studio to review and revoke your connected devices. Revocation blocks subsequent authorized requests. Expired or revoked connections need a new approval. A maximum of 20 active devices per principal and workspace is enforced.

## Resolve installation problems

| Symptom | Check |
| --- | --- |
| Integration or discovery returns 404 | Enabled flag, registered routes, optional migrations and configuration cache |
| Approval opens the wrong page | `studio_url` must point to the real Studio interface |
| Approval fails | Matching code, unexpired request, write access, extension and upload permissions |
| Connection keeps waiting | Complete approval in Studio, then reopen the popup to refresh its state |
| Connected workspace is missing from choices | Approve it separately and confirm workspaces are enabled globally |
| Browser refuses page access | Check the requested source host, browser site access and restricted-page rules |
| An upload fails after pairing | Review [transfer diagnostics](/browser-extension/transfers#troubleshooting) |

For files behind a website login, review [Download through the browser](/browser-extension/preferences#website-access-and-browser-session).
