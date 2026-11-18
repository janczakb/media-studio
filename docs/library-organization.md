---
title: "Organize a workspace"
---

Use **Favorites** to keep files and folders close at hand. **Recent** shows files
you opened in the workspace. Each member has their own list. Members with view
access can use both features. Items disappear from these views while they are
in the trash or while the member lacks access to their source.

In the library, enter selection mode, select items, then choose **Move** or
**Move to trash**. The trash view offers **Restore**. You can select up to 100
items in one operation. Select loaded items acts on the currently loaded page
window. The operation checks current permissions and revisions for every item.
A conflict leaves the entire selection unchanged. Refresh the list and select
the items again before retrying.

Files owned by a read-only source cannot be moved, trashed or restored through
Studio. A file still used in the application cannot be moved to the trash.
You can trash a nonempty managed folder. Studio checks its contents before committing the operation. [Trash, restore and retention](/trash)

Use [notes](/notes) for briefs and checklists beside your files. See [folder navigation](/folder-navigation) for paging and deep paths.

Use the [Photos timeline](/photos) to browse images, videos and favorites grouped by date.

## Use the API from another interface

The routes below are relative to `/media-studio/api/v1/workspaces/{workspace}`.
Use your application's authentication and CSRF settings.

| Request | Purpose |
| --- | --- |
| `GET /entries?view=starred` | Browse favorites across the workspace |
| `GET /entries?view=recent` | Browse recently opened files across the workspace |
| `PUT /entries/{entry}/star` | Set a favorite with `{"starred":true}` or `{"starred":false}` |
| `POST /entries/{entry}/visit` | Record an explicit file open. Returns HTTP 204 |
| `POST /entries/bulk` | Move, trash or restore a set of entries |

Personal views accept the same `search`, `limit` and opaque `cursor` parameters
as the regular library. Omit `parent` for these views. Open a file to record a
visit. Reading its thumbnail, original or preview URL does not record a visit.

To move two items to another folder, send their current revisions and the
destination folder's public ID.

```json
{
  "operation": "move",
  "items": [
    {"id": "01JABC00000000000000000001", "revision": 3},
    {"id": "01JABC00000000000000000002", "revision": 5}
  ],
  "parent": "01JABC00000000000000000003"
}
```

Use `"parent": null` to move to the workspace root. Omit `parent` for
`"operation": "trash"` and `"operation": "restore"`. The result contains
updated cards in `items`. Use their new revisions in subsequent requests.

## Audio and video preview

Studio opens playable audio and video in the fullscreen viewer. Playback uses
the selected file's authenticated version URL and the browser's media controls.
The grid does not request playback bytes. When the preview opens, the player
requests metadata and starts playback when the user presses Play. Seeking uses
HTTP byte ranges on supported disks, including private S3.

Add the MIME types your application accepts to `upload.mime_types` in the
published configuration. Common examples are `audio/mpeg`, `audio/wav`,
`audio/x-wav`, `video/mp4` and `video/webm`. Playback depends on the browser
supporting the file's codec. Unsupported files remain downloadable.
