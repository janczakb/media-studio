---
title: "File comments and team mentions"
---

Discuss files from the **Comments** tab in the preview's right panel. Comments are separate from image annotations and never change the original file. They work for images, videos, documents and notes.

Comments are visible to authenticated members of the workspace who have permission to read comments. The internal file thread remains in the workspace. [Verified-email share invitations](/publish-and-invite#invite-reviewers-by-email) can have a separate discussion on the shared page.

## Enable or disable comments

The Laravel configuration controls access on the server:

```php
// config/media-studio.php
'comments' => ['enabled' => true],
```

Set `enabled` to `false` to disable all comment endpoints and hide the Comments tab in Studio. Existing comments remain in the database.

An individual JavaScript instance can additionally hide comments:

```js
mountMediaStudio(element, {
  transport: createHttpTransport({baseUrl: '/media-studio/api/v1'}),
  comments: false,
});
```

Hiding the interface does not replace server permissions.

## Permissions

Run `php artisan media-studio:permissions --guard=web` after upgrading, then assign these permissions to your existing Spatie roles:

| Permission | Allows |
| --- | --- |
| `studio.comments.view` | Read workspace file comments |
| `studio.comments.create` | Add comments and edit or delete your own comments |
| `studio.comments.resolve` | Resolve or reopen a conversation item |
| `studio.comments.manage` | Edit or delete other members' comments |

Workspace membership and `studio.view` are also required. Moderation permission does not grant workspace access. Removing a member revokes access to comments in that workspace.

## Mention a teammate

Type `@`, begin typing a name and select a teammate. The picker searches only existing workspace members and returns up to 20 results. Up to 10 people can be mentioned in one comment. Mentions are stored as structured references to the principal type and immutable user ID. Display names are resolved from the current profile, so renaming a teammate updates existing mentions. Names and avatars use the same configurable member profile resolver as the rest of Studio; email addresses are not returned by the comment API.

When notifications are enabled, mentioned teammates receive an item in the existing Studio notification inbox. Mentioning yourself does not generate a notification. Saving a retried request does not create duplicate comments or duplicate notifications. Editing a comment notifies only newly added mentions.

## Edit, resolve and delete

Use the comment menu to edit or delete your comment. Deletion requires confirmation. Resolve and reopen controls are available according to permissions. Filter the panel by all, open or resolved comments.

Each save includes a revision. If another person changed the comment, refresh before saving to avoid overwriting their changes. The composer supports plain text and atomic mention chips. Pasted HTML becomes plain text. Only text and validated identity references are stored; arbitrary markup and attributes are not accepted.

## Installation and resource limits

Publish and run the new `file_comments` migration using the normal Studio migration workflow. The dedicated table has indexes for file-scoped cursor pagination and resolution filters. The list automatically fetches the next 30 comments using an opaque cursor as you scroll. Variable-height rows are virtualized with a small overscan. Up to eight pages of comment and profile data are retained; older payloads are fetched again when you scroll back. Lightweight cursor history and row measurements preserve navigation without retaining every comment body. The composer stays at the bottom and the panel tabs remain visible while scrolling. Author profiles are loaded in batches. Comment bodies are limited to 5,000 characters. The server rate-limits writes and mention searches.

No worker is required: the comment and its mention notifications commit in the same database transaction. Disabling activity or notifications disables mention inbox delivery without preventing comments from being saved.
