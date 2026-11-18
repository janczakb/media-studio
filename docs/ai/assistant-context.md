---
title: "Assistant context, attachments and actions"
description: "Understand what the assistant reads, which actions it can perform and the limits applied to each turn."
---

The assistant uses the current workspace, selected library-entry context,
authorized tools, recent conversation history and explicitly uploaded attachments.
A filename or natural-language request helps it locate candidates; it does not
grant permission to read arbitrary storage or invent a matching file.

## Context versus file bytes

Selecting **Current file** supplies a library reference. The tools can inspect
metadata and available indexed text under the actor's permissions. They do not
open every original automatically. To ask a model to inspect an image/PDF directly,
use an explicit supported attachment and a compatible model. Ordinary library
references and temporary chat attachments have different storage lifecycles.

A natural request such as “Find the latest launch brief and open it” can use
actual library search/list results without requiring the user to type its exact
extension. Ambiguous candidates still need clarification. The assistant must
receive an action receipt before claiming it opened a file.

## Temporary attachments

| Limit | Current implementation |
| --- | --- |
| Accepted MIME types | PDF, JPEG, PNG, WebP, plain text, CSV and JSON |
| Individual file | Maximum 8 MiB |
| One message | At most 5 attachments and 16 MiB combined |
| Library references | At most 10 distinct entry IDs per message |
| Prompt | At most 8000 characters and non-empty |

Markdown is accepted when detected as supported plain text; an arbitrary MIME
alias is not automatically accepted. AVIF, video and Office attachments are not
in the shipped staging allowlist. The selected provider/model may impose lower
limits. An accepted attachment format does not guarantee provider support.

Temporary attachments are private and scoped to their workspace, owner and run.
Staging uses `assistant.attachment_directory`, falling back to
`storage_path('app/private/media-studio-assistant')`. Access/current file state is
validated again for provider delivery. Expired/unavailable attachments must be
uploaded again. Configure [assistant maintenance and retention](/assistant-retention)
for their lifecycle; uploading a chat attachment does not add a managed library asset.

## Authorized tool actions

| Tool | Supported actions |
| --- | --- |
| Studio library tool | List, search, get details, rename and move |
| Studio application tool | Open preview, download, favorite, tags, list/create comments, resolve comments, create folder, trash/restore, list/create share links |

Tools execute as the current principal inside the selected workspace and tenant.
Underlying file/source permissions and revision checks still apply. A model's
request cannot grant extra access. Trashing and creating a share are real actions
when the user requests them; the assistant is not an unrestricted administrator.
The current assistant does not generate or edit image bytes; the separate
[image-editing integration](/ai/image-editing) publishes explicit user selections.

## Turn and history bounds

The shipped agent permits at most 6 tool steps and 4096 response tokens. Provider
streaming has a 120-second timeout; the queued run job has a 180-second timeout.
These are implementation limits, not currently exposed model-profile settings.
**Stop** requests cancellation; it is not a provider billing refund or rollback
of a tool action already committed.

Provider context uses at most 58 historical messages within a 64 KiB prompt/history
budget. Saved chats can be much longer because their UI and database are paginated.
This is not unlimited model memory. See [history pagination](/assistant-history)
and [operations](/ai/operations) for concurrency, retention and failure handling.
