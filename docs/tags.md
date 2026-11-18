---
title: "Tagging and tag suggestions"
description: "Assign managed tags, filter by stable identities and map tags from external media."
---

Use tags for concepts that recur across folders. Add tags from File information, select an existing suggestion, or remove an assignment from the file. Use the Tags filter to find the same label in the library or search.

## Add tags to a managed Studio file

Tags are optional metadata. They do not rename files or change the original.
The authenticated actor needs write access. Send the current revision from the
file details; a stale revision returns HTTP 409 instead of replacing newer tags.

```http
PATCH /media-studio/api/v1/workspaces/{workspace}/entries/{entry}/tags
Content-Type: application/json

{
  "revision": 1,
  "tags": [
    {"name": "Hotel", "locale": "en"},
    {"name": "Architektura", "locale": "pl"}
  ]
}
```

The result contains the new `revision` and `tags`, each with `id`, `label` and
`locale`. Reuse an existing identity as `{"id":"{tag-id}"}`. Send an empty `tags`
array to clear the assignments. At most 32 tags can be assigned to one file.
A label is limited to 120 characters. Clearing an assignment does not delete the
tag definition or another file's assignments.

## Search choices from a custom client

```http
GET /media-studio/api/v1/workspaces/{workspace}/filter-options?kind=tags&locale=en&search=hot&limit=30
```

`kind` accepts `tags`, `extensions` or `tagLocales`. The response is:

```json
{"items":[{"id":"{tag-id}","label":"Hotel"}],"nextCursor":null,"hasMore":false}
```

Send `nextCursor` unchanged with the same workspace, kind, search and locale.
Each response examines at most the requested number of tag assignments (maximum
60), rechecking access to external media sources. A tag can occur on several
pages; custom clients should merge choices by ID. An empty page with a non-null
cursor still has more candidates. This keeps authorization checks bounded rather
than scanning all files to fill a dropdown.

Native Studio tags work without Spatie. Mounted Spatie media remain read-only:
update tags through their source workflow and refresh their search projection.
A server-side `tags_using` callback on a Spatie mount can map the source's existing
tags into Studio's bounded search labels. This mapping does not install Spatie
Tags or write a second set of tags back to the source.

```php
use Spatie\MediaLibrary\MediaCollections\Models\Media;

'products' => [
    // Existing mount scope and conversion settings go here.
    'tags_using' => static fn (Media $media): array => array_map(
        static fn (string $name): array => ['name' => $name, 'locale' => 'en'],
        array_slice((array) $media->getCustomProperty('search_tags', []), 0, 32),
    ),
],
```

Run the mount's regular sync after changing source tags. For an application using
Spatie Tags or another tag provider, map that provider's authorized labels in the
same callback and explicitly preload required relations with `tag_relations`.
The full Spatie Tags mutation/translation adapter is a separate integration;
the callback above is a read-only search projection.
