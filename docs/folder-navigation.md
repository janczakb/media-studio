---
title: Folder tree and breadcrumbs
description: Understand lazy expansion, cursor pages, deep links and how to configure the React folder tree.
---

Select a folder name to open it. Its disclosure arrow controls whether its children appear in the left sidebar. Breadcrumbs let you return to an ancestor, including older ancestors available from the ellipsis menu. Filenames and folder names are labels. Use IDs when persisting a selection or addressing an API route.

## What loads

- A branch requests a page of child folders when needed. The default page has 50 folder summaries.
- The virtual list renders the visible rows and a small overscan. Additional pages load with opaque cursors as you scroll.
- On reload, Studio prepares the first pages of previous expanded branches within its expansion budget. It remembers expansion IDs in browser preferences. It does not read the entire library or eagerly exhaust continuation cursors.
- A deeply nested link can use a contextual root. Its breadcrumbs contain a bounded tail and fetch older ancestors separately.

## Configure the sidebar

```ts
import type { StudioOptions } from '@useflex/media-studio';

const layout: StudioOptions['layout'] = {
  folderTreeOptions: {
    pageSize: 50,
    concurrency: 2,
    cachedPages: 12,
  },
};
```

Pass `layout` to your Studio instance. The cache option limits idle pages. Expanded branches have their own bounded windows. Set `folderTree: false` if your host provides the folder navigation itself.

## Read a deep link from REST

```bash
curl --fail-with-body \
  -H "Authorization: Bearer ${STUDIO_API_TOKEN}" \
  "${STUDIO_REST_URL}/workspaces/${WORKSPACE}/folders/${FOLDER}/context"
```

The response contains `folderId`, `root`, `tail`, `hasCollapsedAncestors`, `ancestorCursor`, and the current workspace navigation revision. `totalDepth` may be null. Pass an available cursor to the ancestor endpoint and keep it opaque. If a change feed returns `reset: true`, fetch a fresh outline instead of continuing incremental replay from the previous revision.
