---
title: Notes and links
description: Create workspace notes with formatted text, task lists and links, and edit them using revisions.
---

From the library's **Create** menu, choose **New note**. Use a note for a review checklist, campaign brief, or a collection of reference links beside the project files. Give it a title and choose a background in the editor. Notes are workspace entries with their own document content.

## Create a note from an app

Use the authenticated Studio API. Send the destination folder's public ID as `parent`, or omit it to save at the workspace root. Keep the request key stable when retrying an identical creation.

```http
POST /media-studio/api/v1/workspaces/{workspace}/notes
Content-Type: application/json

{
  "title": "Campaign review",
  "background": "#F2F4F7",
  "key": "review_54053b3dcdf54e7a",
  "document": {
    "type": "doc",
    "content": [
      {
        "type": "paragraph",
        "content": [
          {"type": "text", "text": "Confirm usage rights before publishing."}
        ]
      }
    ]
  }
}
```

Use the returned `entry.id` in `GET /workspaces/{workspace}/notes/{entry}` to reopen it. To save changes, send `PATCH` to the same relative path with the title, background, complete document, and the current `entry.revision`. If it returns `409`, reopen the note before saving again. Writing requires the applicable `notes.create` or `notes.update` permission.

The API accepts structured JSON with nodes such as headings, paragraphs, lists, tasks and code blocks. Links are stored as inline marks. The server enforces supported content and limits the encoded document to 2 MiB. [Studio authentication](/api/authentication)
