---
title: Assistant history and pagination
description: Read saved conversations and message pages through the assistant transport.
---


The optional assistant API returns private, owner/workspace-scoped cursor pages.
Apply the optional assistant migrations, including `000008_index_assistant_pages`,
before enabling the updated application. That migration indexes latest-run lookup
and attachment resolution for a message page. AI provider keys remain server-side.

Conversation list:

```ts
const page = await transport.assistant!.list(workspaceId, null, signal);
const olderChats = page.nextCursor
  ? await transport.assistant!.list(workspaceId, page.nextCursor, signal)
  : null;
```

Pages contain at most 30 conversations. The built-in drawer loads on scroll,
virtualizes visible rows and retains six payload pages. Cursor checkpoints allow
refetching pages that have left the cache. Search is a name-prefix filter.

Message history:

```ts
const latest = await transport.assistant!.show(workspaceId, conversationId, signal);
const older = latest.nextCursor
  ? await transport.assistant!.show(workspaceId, conversationId, signal, {
      before: latest.nextCursor,
    })
  : null;
const newer = older?.newerCursor
  ? await transport.assistant!.show(workspaceId, conversationId, signal, {
      after: older.newerCursor,
    })
  : null;
```

`GET .../assistant/conversations/{conversation}` accepts either `before` or
`after`, never both. Every page contains at most 30 messages, ordered oldest to
newest. Without a cursor it returns the newest page. `nextCursor` points toward
older history; `newerCursor` points toward newer history. A null cursor means
that edge has been reached. Cursors are opaque to application code. All pages
repeat the current run metadata and remain authorized and uncached privately.

The built-in message viewport measures variable row heights, virtualizes the
visible range and keeps at most 180 message payloads. Scrolling toward an evicted
edge reloads that direction. Reading position is anchored when older messages are
prepended. Opening or closing a file preview retains the same assistant DOM,
draft, scroll viewport and active event subscription.

Saved conversation length is separate from AI context size. Saved messages remain
paginated in the database. Provider requests use a rolling window of at most 58
recent historical messages within a 64KiB prompt/history budget. Older content can
be absent from a model request; this is not unlimited model memory or automatic
conversation summarization.
