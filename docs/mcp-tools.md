---
title: "MCP tool reference"
description: "Arguments, permissions, result fields and retry behavior for Studio MCP tools."
---

Call `tools/list` on the configured server for the schemas available to the
current credential. The permission names below use Studio's default `studio.`
prefix. Every tool also requires `studio.mcp.use`. Workspace access, source
policy and tool allowlists still apply on each call.

## Browse and search

These four tools are supported in both authentication modes. Indexed search
requires `media-studio.search.enabled`.

| Tool | Required input | Optional input | Result |
| --- | --- | --- | --- |
| `studio_list_workspaces` | None | `cursor` | Up to 25 visible workspaces, each with `id` and `name`, plus pagination. Requires `studio.view`. |
| `studio_list_files` | `workspace` | `parent`, `search`, `cursor` | Up to 60 file and folder cards, plus pagination. Omit `parent` for the root. `search` matches a name prefix up to 100 characters. Requires `studio.view`. |
| `studio_get_file` | `workspace`, `entry` | None | A `file` metadata object for the entry. Requires `studio.view`. |
| `studio_search_files` | `workspace`, `q` | `cursor` | Authorized ranked matches with pagination and `rankingMayChange`. Query limit 200 characters. Requires `studio.view`. |

File cards include the entry ID, name, kind and revision. They may include
media type, MIME, byte size, dimensions and update timestamp. Use
[file transfer tools](/mcp-transfers) to obtain file bytes.

Pass `nextCursor` back as `cursor` with the same tool, workspace, parent,
query and filters. Use `hasMore` to determine whether to continue. A page may
contain fewer visible items than its limit after policy filtering. Treat
cursors as opaque and restart from the first page after changing filters.
Search uses the configured lexical index. Reading never starts OCR, image
analysis or semantic indexing.

This request lists the first page of root entries whose names start with `chair`.

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "studio_list_files",
    "arguments": {
      "workspace": "01J9GQ4ZPS9Z91FMY3JC5M8HJX",
      "search": "chair"
    }
  }
}
```

Keep `workspace` and `search` unchanged when using the returned cursor.
Indexed search returns at most 60 candidates per page, omits stale and
inaccessible matches, and expires search cursors after 15 minutes.

## Workspaces, folders and file changes

The remaining tools require a [managed connection](/mcp-access). In the tables
below, arguments marked **optional** may be omitted.

| Tool | Arguments | Permission and result |
| --- | --- | --- |
| `studio_create_workspace` | `name`, `key` | `studio.workspaces.create`. Requires workspace creation enabled. Returns `id` and `name`. |
| `studio_create_folder` | `workspace`, `name`; optional `parent` | `studio.folders.create`. Returns the folder entry. |
| `studio_resolve_import_folder` | `workspace`, `path`; optional `parent` | `studio.folders.create`. Resolves or creates a relative path and returns its folder result. Repeat the same path and parent to resume. |
| `studio_rename_entry` | `workspace`, `entry`, `revision`, `name` | `studio.files.update`. Returns the updated entry. |
| `studio_move_entry` | `workspace`, `entry`, `revision`, `parent` | `studio.files.update`. Use explicit `parent: null` for the root. Returns the updated entry. |
| `studio_bulk_move` | `workspace`, `items`, `parent` | `studio.files.update`. Returns updated `items`. Use explicit `parent: null` for the root. |
| `studio_set_favorite` | `workspace`, `entry`, `starred` | `studio.view`. Sets a personal favorite and returns `id` and `starred`. |
| `studio_replace_tags` | `workspace`, `entry`, `revision`, `tags` | `studio.tags.manage`. Replaces manual tags. Returns `revision`, `updatedAt` and `tags`. An empty list clears manual tags. |
| `studio_list_trash` | `workspace`; optional `cursor` | `studio.view`. Returns paginated trashed entries, their revisions and purge status. |
| `studio_trash_entry` | `workspace`, `entry`, `revision` | `studio.files.trash`. Returns the trashed entry. Folder operations follow Studio's normal folder policy. |
| `studio_restore_entry` | `workspace`, `entry`, `revision` | `studio.files.restore`. Returns the restored entry. Start from the revision in Trash. |
| `studio_bulk_trash` | `workspace`, `items` | `studio.files.trash`. Returns the affected entries in `items`. |
| `studio_bulk_restore` | `workspace`, `items` | `studio.files.restore`. Returns the restored entries in `items`. |

An entry result contains `id`, `name`, `kind`, `revision`, `trashedAt`
and `versionId`. A bulk `items` argument is a list of 1–100 unique
`{"id":"<entry ULID>","revision":1}` objects. Studio validates and applies
the explicitly selected batch atomically. Use current revisions and review
the affected entries before trashing a batch. Paths use forward slashes and
are limited to 4096 characters. Workspace names allow up to 160 characters.
Entry names allow up to 191 characters. Tags accept up to 32 names, each up to
120 characters.

A move to the root requires an explicit destination and the current revision.

```json
{
  "name": "studio_move_entry",
  "arguments": {
    "workspace": "01J9GQ4ZPS9Z91FMY3JC5M8HJX",
    "entry": "01J9GQ4ZPS9Z91FMY3JC5M8HJY",
    "revision": 4,
    "parent": null
  }
}
```

## Comments

These tools require the comments feature. Comment text is plain text and may
contain up to 5000 characters. `mentions` accepts up to 10 distinct membership
ID strings from the mention lookup. Edit and delete require author or moderation
rights in addition to the listed permission.

| Tool | Arguments | Permission and result |
| --- | --- | --- |
| `studio_list_comments` | `workspace`, `entry`; optional `filter`, `cursor` | `studio.comments.view`. `filter` accepts `all`, `open`, `resolved`. Returns paginated comments. |
| `studio_find_comment_mentions` | `workspace`, `entry`; optional `search` | `studio.comments.create`. Returns `items` with `memberId` and `name`. Prefix limit 100 characters. |
| `studio_create_comment` | `workspace`, `entry`, `body`, `key`; optional `mentions` | `studio.comments.create`. Returns the created comment. |
| `studio_edit_comment` | `workspace`, `entry`, `comment`, `revision`, `body`; optional `mentions` | `studio.comments.view`. Returns the updated comment. |
| `studio_resolve_comment` | `workspace`, `entry`, `comment`, `revision`, `resolved` | `studio.comments.resolve`. Use `false` to reopen. Returns the updated comment. |
| `studio_delete_comment` | `workspace`, `entry`, `comment`, `revision` | `studio.comments.view`. Returns the deletion result for the comment. |

Comment results expose the comment ID and revision, body, timestamps, resolution
and deletion flags, with `authorName` when available.

## File transfer tools

| Tool | Arguments | Result |
| --- | --- | --- |
| `studio_get_upload_policy` | `workspace` | Effective ordinary upload and resumable upload limits. |
| `studio_prepare_file_upload` | `workspace` | `uploadPath` for an ordinary multipart form upload. |
| `studio_prepare_image_import` | `workspace` | `uploadPath` for one source image with supplied renditions. |
| `studio_prepare_file_download` | `workspace`, `entry`; optional `version` | `downloadPath` for the resolved file version. Omitting `version` resolves the current version. |
| `studio_begin_upload` | `workspace`, `name`, `size`, `key`; optional `parent`, `direct` | Resumable upload card, with part URL prefix for local transport. |
| `studio_get_upload` | `workspace`, `session` | Current card and accepted part numbers. |
| `studio_sign_upload_part` | `workspace`, `session`, `part`, `sha256` | S3 part URL and required request headers. Requires Multipart enabled. |
| `studio_record_upload_part` | `workspace`, `session`, `part`, `etag` | `accepted: true`. Requires Multipart enabled. |
| `studio_complete_upload` | `workspace`, `session` | Published file entry. Reusing the same completed session returns its entry. |
| `studio_cancel_upload` | `workspace`, `session` | `cancelled: true` after requesting cleanup of an unfinished upload. |

Download requires `studio.files.download`. Other transfer tools require
`studio.uploads.create`. Starting a resumable upload requires
`media-studio.upload.sessions.enabled` and workspace support. See
[file transfer workflows](/mcp-transfers) for the binary requests and upload
card shape.

## Revisions, retries and responses

IDs are ULIDs unless a field explicitly uses a membership ID or cursor.
Revisions are positive integers. When an operation takes a `key`, supply
16–100 characters from letters, numbers, underscore and hyphen. Reuse the key
with the same credential and arguments after an uncertain response. For
revision conflicts, reload the entry or comment before retrying.

Managed operations return their payload in `result.structuredContent`.
The four read tools expose JSON in `result.content[].text`. Treat
`result.isError` and top-level JSON-RPC `error` as failures. An HTTP 200
alone does not indicate that the operation succeeded. See
[troubleshooting](/mcp-troubleshooting) for error handling.
