---
title: "Share files and folders"
---

Create a share link for a file, a folder, or a selection from the file actions menu. Give the link a name, choose whether visitors can download originals, and optionally set a password or an expiry date. Copy the URL shown after creation and send it to the people you want to invite. Visitors can use an ordinary share link without a Studio account. For verified recipients, indexed publishing and copy controls, see [Publish or invite reviewers](/publish-and-invite).

Review optional [share analytics](/share-analytics) to see eligible visits, average duration and country totals.

## Manage links

Use **Shared** to find your active links, expired links, and revoked links. Filter the list by files, folders, or selections. Workspace owners can manage every link in their workspace. Editors can manage the links they created.

## Let guests upload files

[Configure guest uploads](/guest-uploads) to receive files in a managed folder from visitors without a Studio account.

## Link behavior and access

Revoke a link to close access immediately. Rotate a link to replace its URL and invalidate the previous URL. Studio shows the full URL when it creates or rotates a link. Keep that URL somewhere safe if you need it again.

After creating or rotating a link, choose **Show QR code** to display a scannable code. The code is generated in your browser without sending the link to a QR service. It contains the link, so anyone who can scan it can open the share subject to its password and expiry settings.

Folder links follow the current contents of the folder. Moving an item out of that folder removes it from the shared collection unless you also shared the item directly. Links to files follow the current file version. Trashed files and files the creator can no longer access are unavailable through a share.

**Allow downloads** controls the download action and access to the original file through the download endpoint. Visitors can still save content their browser is allowed to preview. Use an expiry date or revoke the link when access should end.

See [Archives and bulk downloads](/archive-downloads#stream-a-zip-archive) for folder and collection ZIPs.

## Prepare an archive in the background

[Continue to Archives and bulk downloads](/archive-downloads#prepare-an-archive-in-the-background).

## Laravel configuration

The same sharing API works with Laravel and Filament. Publish and run the Studio migrations before enabling it in an existing app. Set your application URL to its canonical HTTPS origin so people can open the links you send them.

```php
// config/media-studio.php
'sharing' => [
    'enabled' => true,
    'ability' => null,
    'max_items' => 120,
    'max_links' => 1000,
    'password_attempts' => 5,
],
```

`max_items` limits the number of files or folders selected when you create a link. A folder can contain more items because its contents are paginated. `max_links` limits stored link records per workspace, including expired and revoked links. `password_attempts` limits password submissions per IP and link per minute. Use a shared cache such as Redis for that limit when you run Studio on multiple application instances.

Link management uses Spatie permissions: `studio.shares.view` to list links and `studio.shares.manage` to create or change them. Assign permissions through your application's Spatie roles. To use existing names, map `shares.view` and `shares.manage` in `media-studio.permissions.abilities`.

Existing guest links require a valid owner or editor membership and the creator's current `studio.shares.manage` permission. Studio rechecks tenant and media source access on every request. Revoking that permission disables the delegated link as well.

Guest download and upload permissions are delegated from the creator, not independent grants. Removing `studio.files.download` hides download actions and blocks both individual and collection downloads while keeping otherwise authorized previews available. Removing `studio.uploads.create` hides the guest upload form and blocks uploads. Removing `studio.shares.manage`, disabling sharing, removing workspace membership or revoking tenant access closes the delegated access. The background archive worker repeats those checks before publication.

The guest page works without JavaScript. It includes password entry, paginated folders, upload validation and success messages. A repeated submission of the same upload form uses one upload receipt rather than consuming the link quota twice. Locked preview links return browser visitors to the password form; JSON clients receive `423`. Expired, revoked or inaccessible links display the same unavailable state without revealing the collection contents. Rate-limited visitors get a retry message. All share responses, including errors, use private no-store caching and do not send the link as a referrer.

Watermarks are outside the current product scope; there is no watermark processing or configuration requirement for guest access.

Public links use the Laravel `web` middleware for password sessions. Use a session store shared by all application instances in a cluster. Configure your reverse proxy and CDN to respect `Cache-Control: private, no-store` on the share routes.

## API

All management routes are relative to the configured Studio API prefix. Use the normal authenticated Studio transport and CSRF protection.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/workspaces/{workspace}/shares` | List shares with `status`, `kind`, `mine`, `limit`, and `cursor` filters. |
| `POST` | `/workspaces/{workspace}/shares` | Create a link and return its URL. |
| `GET` | `/workspaces/{workspace}/shares/{share}/entries` | List the selected root files or folders. |
| `PATCH` | `/workspaces/{workspace}/shares/{share}` | Edit a link or revoke it. Include its current `revision`. |
| `POST` | `/workspaces/{workspace}/shares/{share}/rotate` | Generate a new URL. Include the current `revision`. |

For example, create a time-limited link to two entries with the following body.

```json
{
  "title": "Approved campaign assets",
  "entryIds": ["01ARZ3NDEKTSV4RRFFQ69G5FAV", "01ARZ3NDEKTSV4RRFFQ69G5FAW"],
  "allowDownload": true,
  "allowArchive": true,
  "expiresAt": "2027-06-01T12:00:00Z"
}
```

Add `password` to protect the link. Set `password` or `expiresAt` to `null` in a `PATCH` request to remove that requirement. If the server returns `409`, reload the link before retrying the edit.
