<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

final class OperationCatalog
{
    public const TOOLS = [
        'studio_create_workspace' => ['operation' => 'workspaces.create', 'description' => 'Create a workspace in the authenticated tenant. Reuse the key when retrying.', 'fields' => ['workspaceName', 'key'], 'idempotent' => true, 'feature' => 'media-studio.workspace_creation'],
        'studio_create_folder' => ['operation' => 'folders.create', 'description' => 'Create a folder at the workspace root or inside another folder.', 'fields' => ['workspace', 'name', 'parent']],
        'studio_resolve_import_folder' => ['operation' => 'folders.create', 'description' => 'Resolve or create a relative import folder under an existing parent. Reuse the path to resume.', 'fields' => ['workspace', 'parent', 'folderPath'], 'idempotent' => true],
        'studio_rename_entry' => ['operation' => 'files.update', 'description' => 'Rename a file or folder using its current revision.', 'fields' => ['workspace', 'entry', 'revision', 'name']],
        'studio_move_entry' => ['operation' => 'files.update', 'description' => 'Move a file or folder. Set parent to null to move it to the workspace root.', 'fields' => ['workspace', 'entry', 'revision', 'destination']],
        'studio_trash_entry' => ['operation' => 'files.trash', 'description' => 'Move a file or empty folder to the trash. Confirm the exact item with the user first.', 'fields' => ['workspace', 'entry', 'revision'], 'destructive' => true],
        'studio_restore_entry' => ['operation' => 'files.restore', 'description' => 'Restore a trashed file or folder using the revision returned by the trash operation.', 'fields' => ['workspace', 'entry', 'revision']],
        'studio_list_trash' => ['operation' => 'view', 'description' => 'Read a page of trashed files and folders, including their current revisions.', 'fields' => ['workspace', 'cursor'], 'readOnly' => true],
        'studio_bulk_move' => ['operation' => 'files.update', 'description' => 'Move up to 100 selected entries to one destination. Set parent to null for the root.', 'fields' => ['workspace', 'items', 'destination']],
        'studio_bulk_trash' => ['operation' => 'files.trash', 'description' => 'Move up to 100 explicitly selected entries to the trash. Confirm the list with the user first.', 'fields' => ['workspace', 'items'], 'destructive' => true],
        'studio_bulk_restore' => ['operation' => 'files.restore', 'description' => 'Restore up to 100 explicitly selected entries.', 'fields' => ['workspace', 'items']],
        'studio_set_favorite' => ['operation' => 'view', 'description' => 'Set the authenticated account’s personal favorite flag on one entry.', 'fields' => ['workspace', 'entry', 'starred'], 'idempotent' => true],
        'studio_replace_tags' => ['operation' => 'tags.manage', 'description' => 'Replace an entry’s manual tags using its current revision. An empty list clears tags. Confirm before removing tags.', 'fields' => ['workspace', 'entry', 'revision', 'tags'], 'destructive' => true],
        'studio_list_comments' => ['operation' => 'comments.view', 'description' => 'Read a page of comments on an accessible file.', 'fields' => ['workspace', 'entry', 'filter', 'cursor'], 'readOnly' => true, 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_find_comment_mentions' => ['operation' => 'comments.create', 'description' => 'Find team members who may be mentioned in comments on a file.', 'fields' => ['workspace', 'entry', 'search'], 'readOnly' => true, 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_create_comment' => ['operation' => 'comments.create', 'description' => 'Add a plain-text comment and optionally notify specific team members. Reuse the key when retrying.', 'fields' => ['workspace', 'entry', 'body', 'mentions', 'key'], 'idempotent' => true, 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_edit_comment' => ['operation' => 'comments.view', 'description' => 'Edit an existing comment. Author or moderation rights are required.', 'fields' => ['workspace', 'entry', 'comment', 'revision', 'body', 'mentions'], 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_resolve_comment' => ['operation' => 'comments.resolve', 'description' => 'Resolve or reopen a comment.', 'fields' => ['workspace', 'entry', 'comment', 'revision', 'resolved'], 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_delete_comment' => ['operation' => 'comments.view', 'description' => 'Delete an existing comment. Confirm with the user first. Author or moderation rights are required.', 'fields' => ['workspace', 'entry', 'comment', 'revision'], 'destructive' => true, 'feature' => 'media-studio.comments.enabled', 'featureDefault' => true],
        'studio_prepare_file_download' => ['operation' => 'files.download', 'description' => 'Return a same-origin authenticated path for downloading a file version. Download permission is required.', 'fields' => ['workspace', 'entry', 'version'], 'readOnly' => true],
        'studio_get_upload_policy' => ['operation' => 'uploads.create', 'description' => 'Get permitted formats and upload size limits.', 'fields' => ['workspace'], 'readOnly' => true],
        'studio_prepare_file_upload' => ['operation' => 'uploads.create', 'description' => 'Prepare an ordinary file upload. Send multipart/form-data to the returned same-origin path.', 'fields' => ['workspace']],
        'studio_prepare_image_import' => ['operation' => 'uploads.create', 'description' => 'Prepare an image bundle import with named conversions attached to one source version.', 'fields' => ['workspace']],
        'studio_begin_upload' => ['operation' => 'uploads.create', 'description' => 'Begin a resumable upload. Send bytes separately to the returned HTTP path or sign a part for direct S3 uploads.', 'fields' => ['workspace', 'name', 'size', 'parent', 'key', 'direct'], 'idempotent' => true, 'feature' => 'media-studio.upload.sessions.enabled'],
        'studio_get_upload' => ['operation' => 'uploads.create', 'description' => 'Get accepted part numbers and the state of a resumable upload owned by this client.', 'fields' => ['workspace', 'session'], 'readOnly' => true],
        'studio_sign_upload_part' => ['operation' => 'uploads.create', 'description' => 'Sign one S3 part for the declared SHA-256 checksum. Use the returned URL and headers for a direct PUT.', 'fields' => ['workspace', 'session', 'part', 'sha256'], 'openWorld' => true, 'feature' => 'media-studio.upload.sessions.multipart.enabled'],
        'studio_record_upload_part' => ['operation' => 'uploads.create', 'description' => 'Record the quoted ETag received from S3 for one signed part.', 'fields' => ['workspace', 'session', 'part', 'etag'], 'feature' => 'media-studio.upload.sessions.multipart.enabled'],
        'studio_complete_upload' => ['operation' => 'uploads.create', 'description' => 'Verify a resumable upload and publish it as one file after all parts have arrived.', 'fields' => ['workspace', 'session'], 'idempotent' => true],
        'studio_cancel_upload' => ['operation' => 'uploads.create', 'description' => 'Cancel an unfinished upload owned by this client and clean its staged data.', 'fields' => ['workspace', 'session'], 'destructive' => true],
    ];

    public const FIELDS = [
        'workspace' => ['rules' => 'required|string|ulid', 'description' => 'Workspace ULID.'],
        'version' => ['rules' => 'nullable|string|ulid', 'description' => 'Version ULID, or omit to download the current version.'],
        'entry' => ['rules' => 'required|string|ulid', 'description' => 'File or folder ULID.'],
        'parent' => ['rules' => 'nullable|string|ulid', 'description' => 'Parent folder ULID, or null for root.'],
        'destination' => ['name' => 'parent', 'rules' => 'present|nullable|string|ulid', 'description' => 'Destination folder ULID, or null for root.'],
        'revision' => ['rules' => 'required|integer|min:1', 'description' => 'Current revision from the most recent read.'],
        'name' => ['rules' => 'required|string|max:191', 'description' => 'Name including the extension for a file.'],
        'folderPath' => ['name' => 'path', 'rules' => 'required|string|max:4096', 'description' => 'Relative folder path using forward slashes.'],
        'workspaceName' => ['name' => 'name', 'rules' => 'required|string|max:160', 'description' => 'Name of the new workspace.'],
        'key' => ['rules' => 'required|string|min:16|max:100', 'description' => 'Unique retry key using letters, digits, dashes or underscores.'],
        'items' => ['rules' => 'required|array|list|min:1|max:100', 'description' => 'Entries identified by their ULIDs and current revisions.'],
        'tags' => ['rules' => 'present|array|list|max:32', 'description' => 'Tag names in the workspace’s default tag locale.'],
        'starred' => ['rules' => 'required|boolean', 'description' => 'True to add to favorites, false to remove.'],
        'filter' => ['rules' => 'nullable|string|in:all,open,resolved', 'description' => 'Filter comments by resolution state.'],
        'cursor' => ['rules' => 'nullable|string|max:4096', 'description' => 'Opaque cursor from the previous page.'],
        'search' => ['rules' => 'nullable|string|max:100', 'description' => 'Start of the team member name.'],
        'comment' => ['rules' => 'required|string|ulid', 'description' => 'Comment ULID.'],
        'body' => ['rules' => 'required|string|max:5000', 'description' => 'Plain-text comment.'],
        'mentions' => ['rules' => 'sometimes|array|max:10', 'description' => 'Workspace membership IDs returned by the mention search.'],
        'resolved' => ['rules' => 'required|boolean', 'description' => 'True to resolve, false to reopen.'],
        'size' => ['rules' => 'required|integer|min:1', 'description' => 'Declared file size in bytes.'],
        'direct' => ['rules' => 'sometimes|boolean', 'description' => 'Prefer S3 Multipart when the workspace supports it.'],
        'session' => ['rules' => 'required|string|ulid', 'description' => 'Upload session ULID.'],
        'part' => ['rules' => 'required|integer|min:0|max:9999', 'description' => 'Zero-based part number.'],
        'sha256' => ['rules' => 'required|string|size:64', 'description' => 'Lowercase SHA-256 hex checksum of the part.'],
        'etag' => ['rules' => 'required|string|max:128', 'description' => 'Quoted ETag returned by S3.'],
    ];
}
