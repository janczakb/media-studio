---
title: "Filters and sorting"
---

In **All files**, use Type, Extension, Tags, and Date & size. With member listing available and permission to view members, Studio offers **Uploaded by** in place of the Tag locale menu. Each menu preserves the other active filters. Filters stay
active when opening a child folder. Preview arrows follow the same filtered
and sorted result set.

| Menu | Choices |
|---|---|
| Type | Code, documents, spreadsheets, presentations, images, videos, audio, archives, fonts and other files; select more than one |
| Extension | One or more extensions, such as `.avif`, `.jpg`, `.pdf` or `.xlsx` |
| Tags | Search by the beginning of a tag name; match any selected tag or require all selected tags |
| Uploaded by | Search workspace members and choose one or more uploaders when member listing is authorized |
| Tag locale | Restrict tags to a configured language when the uploader menu is unavailable |
| Date & size | Inclusive UTC date range and minimum/maximum size in MiB |

Date presets cover the last 7 or 30 days. The API receives byte counts rather
than MiB. Selecting several file types or extensions matches any selected item
within that menu. Different menus combine: for example **Images + .avif + Hotel**
returns AVIF images carrying the Hotel tag.

Sort by name, last modified, file size or file type. Select the current sorter
again to reverse its direction. Current controls apply to All files.

## API queries

The request is scoped to the authenticated workspace and optional parent folder.

```http
GET /media-studio/api/v1/workspaces/{workspace}/entries?sort=size&direction=desc&filters[type]=image&filters[minBytes]=1048576&filters[dateFrom]=2026-11-01&filters[dateTo]=2026-11-07
```

The response contains an opaque `nextCursor`. Send it unchanged with the same
filter and sort options to fetch the next page. A cursor from another workspace,
principal, parent, search or filter state is rejected. Requests return at most
120 items.

Neighbors use `direction=next` or `direction=previous`. Include `sortDirection`
to preserve an ascending or descending sort in that request.

## Multiple types, extensions and tags

```http
GET /media-studio/api/v1/workspaces/{workspace}/entries?filters[types][0]=image&filters[types][1]=document&filters[extensions][0]=jpg&filters[extensions][1]=pdf
```

Type IDs are `code`, `document`, `spreadsheet`, `presentation`, `image`, `video`,
`audio`, `archive`, `font` and `other`. Extension values omit the leading dot;
uppercase extensions are normalized to lowercase. A request accepts up to 10
types, 32 extensions and 32 tag identities. Empty selections remove that filter.
File categories and extensions have indexed projections, including existing
records. Renaming a file updates its category/extension projection automatically.

```http
GET /media-studio/api/v1/workspaces/{workspace}/entries?filters[tagIds][0]={tag-id}&filters[tagIds][1]={another-tag-id}&filters[tagMatch]=all&filters[tagLocale]=en
```

Use stable tag IDs returned by suggestions or assignment. `tagMatch=any` is the
default; `all` requires every selected tag. Tags from another workspace never
match files in the current one. A locale restricts tag labels/assignments to that
language. Native tags with the same name in different locales have separate
identities.

## Filter by uploader

Use searchable workspace members as choices. A client passes up to 32 workspace member IDs as `filters.uploaderIds`. Use the member IDs returned by the workspace members endpoint, preserving the workspace scope.

```http
GET /media-studio/api/v1/workspaces/{workspace}/entries?filters[uploaderIds][0]=42&filters[uploaderIds][1]=73
```

## Configure extension choices and tag languages

The extension menu uses a configurable format catalog; it does not inspect your
bucket or expose names from inaccessible files. The default catalog covers the
built-in categories. Add or replace choices in your application configuration.

```php
'browse' => [
    'revision' => 1,
    'filters' => [],
    'sorters' => [],
    'extensions' => ['avif', 'jpg', 'pdf', 'webp', 'xlsx', 'custom'],
    'tag_locales' => ['en', 'pl', 'de'],
],
```

Extension choices are lowercase values without a dot, with at most 32 characters
per value and at most 512 configured choices. Configure between one and 32 tag
locales. Changing the list changes only the available choices, not the original
files or existing assignments.

## Add tags to a managed Studio file

[Read Tagging and tag suggestions](/tags#add-tags-to-a-managed-studio-file).

## Search choices from a custom client

[Read Tagging and tag suggestions](/tags#search-choices-from-a-custom-client).

## Add an application filter

[Read Custom filters and sorters](/custom-library-filters#add-an-application-filter).

## Add a sorter

[Read Custom filters and sorters](/custom-library-filters#add-a-sorter).
