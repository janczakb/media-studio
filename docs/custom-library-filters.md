---
title: "Custom filters and sorters"
description: "Register authorized filters and deterministic sort orders in your Laravel application."
---

Use the extension points below for host-specific criteria. Test a custom rule using a user allowed to see the matching files and a user limited to a different workspace or source.

## Add an application filter

Register a server class under a stable key in your application's published
configuration. Only registered keys can be requested.

```php
use App\Media\DocumentsFilter;
use App\Media\NewestSorter;

'browse' => [
    'revision' => 1,
    'filters' => ['documents' => DocumentsFilter::class],
    'sorters' => ['newest' => NewestSorter::class],
],
```

A filter adds a WHERE predicate and validates its accepted values. Studio nests
that predicate inside its workspace, folder and access scope. The filter receives
the authorized workspace and principal when it needs an application-specific
restriction.

```php
namespace App\Media;

use Illuminate\Database\Query\Builder;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\BrowseFilter;

final class DocumentsFilter implements BrowseFilter
{
    public function apply(
        Builder $predicate,
        string $value,
        object $workspace,
        Principal $principal,
    ): void {
        abort_unless($value === 'enabled', 422);

        $predicate->where('browse_type', 'document')
            ->orWhere('browse_type', 'pdf');
    }
}
```

Configure the matching control when mounting the SDK. Filter IDs and option IDs
must match what the server accepts. Each custom filter supports up to 100 options.
A request can apply up to eight custom filters.

```ts
mountMediaStudio(element, {
    transport,
    stylesheetUrl: '/assets/studio.css',
    browse: {
        filters: [{
            id: 'documents',
            label: 'Document group',
            options: [{id: 'enabled', label: 'Documents and PDFs'}],
        }],
        sorters: [{id: 'newest', label: 'Added to library'}],
    },
});
```

The SDK sends `customFilters[documents]=enabled`. Removing the selection
omits that predicate. Labels are supplied by the application and can use its
translation system. Registering a client control alone does not enable a server
filter.

## Add a sorter

A custom sorter returns one indexed, non-null scalar column from `fms_entries`.
Studio uses the entry ID as the final tie-breaker for keyset pagination.

```php
namespace App\Media;

use UseFlex\MediaStudio\Library\BrowseSorter;

final class NewestSorter implements BrowseSorter
{
    public function column(): string
    {
        return 'id';
    }
}
```

Select **Added to library** again to reverse the direction. For an application
column, add its migration and appropriate workspace/folder index before
registering the sorter. Nullable values need a non-null projection. Studio's
built-in size, type and date sorts have indexed projections that also cover
existing records.

Increase `browse.revision` whenever a deployed filter changes its meaning.
Existing browse and preview cursors then expire with HTTP 422, prompting a fresh
listing. Changing a registered sorter’s physical column also expires its cursors.
