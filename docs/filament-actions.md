---
title: "Native Filament actions"
---

Use Studio actions on Filament pages, record actions and table bulk actions.
They open native Filament forms and confirmations and call the same library
operations as the standalone Studio interface. Install Filament in the host
application and register Studio's authenticated routes, configuration and
migrations first.

## Available actions

Import classes from
`UseFlex\MediaStudio\Integrations\Filament\Actions`.

| Action | Behavior |
| --- | --- |
| [`CreateFolderAction`](#createfolderaction) | Create a folder in the workspace root or a selected folder |
| [`RenameAction`](#renameaction) | Rename the mapped Studio entry |
| [`MoveAction`](#moveaction) | Move the entry to an authorized destination folder |
| [`TrashAction`](#trashaction) | Move the entry to the reversible trash |
| [`RestoreAction`](#restoreaction) | Restore a trashed entry |
| [`DownloadAction`](#downloadaction) | Download the mapped file’s current version through Studio's authenticated route |
| [`SelectFileAction`](#selectfileaction) | Select an existing file or upload through the Studio picker |
| [`MoveBulkAction`](#movebulkaction) | Move an explicit selection of up to 100 entries |
| [`TrashBulkAction`](#trashbulkaction) | Move the entire selection to trash |
| [`RestoreBulkAction`](#restorebulkaction) | Restore the entire selection |

The standard Filament methods remain available, including `label()`, `icon()`,
`color()`, `visible()`, `authorize()`, lifecycle hooks and notifications.
Studio checks workspace membership and source ownership when an operation runs.
Configuring an action's visibility does not grant permission to its underlying file.

## Add a page action

A page that uses native Filament actions can expose this method. The workspace
comes from trusted application state. For a plain Livewire component, implement
Filament's `HasActions` and `HasSchemas` contracts and their interaction traits.

```php
use UseFlex\MediaStudio\Integrations\Filament\Actions\CreateFolderAction;

public function createFolderAction(): CreateFolderAction
{
    return CreateFolderAction::make('createFolder')
        ->workspace(fn (): string => $this->record->studio_workspace_id)
        ->label('New project folder')
        ->afterEntry(function (object $entry): void {
            // Refresh your application's folder list after Studio commits.
        });
}
```

Render the action using the normal Filament entry point.

```blade
{{ $this->createFolderAction }}
<x-filament-actions::modals />
```

The folder selector searches authorized managed folders by name. It returns at
most 119 matches plus the workspace root. Type more of the name to narrow the list.

## Map your table record to a Studio file

The application's model may hold a Studio asset ID while its own primary key
identifies a separate record. Supply `entry()` to resolve the current Studio ID
and revision. Keep this mapping on the server.

```php
use App\Models\GalleryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RenameAction;

RenameAction::make('renameMedia')
    ->workspace(fn (GalleryItem $record): string => $record->studio_workspace_id)
    ->entry(function (GalleryItem $record): array {
        $entry = DB::table('fms_entries')
            ->where('public_id', $record->studio_asset_id)
            ->firstOrFail(['public_id', 'revision']);

        return [
            'id' => $entry->public_id,
            'revision' => (int) $entry->revision,
        ];
    })
    ->authorize(fn (GalleryItem $record): bool => Gate::allows('update', $record));
```

When the table directly lists Studio entries, the default mapping reads
`public_id` and an integer `revision` from the record. Add an integer cast for
`revision` to that Eloquent model. Array records also need the unique
`__key` required by Filament.

An open mutation form carries an encrypted confirmation bound to the member,
workspace, action, entry selection and revisions. Confirmation expires after
30 minutes. Changing the file in another session produces a conflict. Reopen
the action to review the current file before trying again.

`afterEntry(fn (object $entry) => ...)` receives the updated Studio row after
a successful single-entry mutation. Use normal Filament hooks to integrate
application-specific behavior.

## Add bulk actions

On a native Filament table backed by Studio entry models, register toolbar actions.
This example assumes the table query is already scoped to the current application's
authorized workspace and casts `revision` to integer.

```php
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\MoveBulkAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RestoreBulkAction;
use UseFlex\MediaStudio\Integrations\Filament\Actions\TrashBulkAction;

public function table(Table $table): Table
{
    return $table
        ->query($this->authorizedEntryQuery())
        ->toolbarActions([
            MoveBulkAction::make()
                ->workspace($this->workspaceId)
                ->authorizeIndividualRecords(
                    fn ($record): bool => Gate::allows('update', $record),
                ),
            TrashBulkAction::make()
                ->workspace($this->workspaceId)
                ->authorizeIndividualRecords(
                    fn ($record): bool => Gate::allows('delete', $record),
                ),
            RestoreBulkAction::make()
                ->workspace($this->workspaceId)
                ->authorizeIndividualRecords(
                    fn ($record): bool => Gate::allows('restore', $record),
                ),
        ]);
}
```

Select up to 100 individual records. Bulk actions reject select-all-across-the-
library mode. A denied, missing or changed entry rejects the entire operation.
The folder cycle, existing usage, source ownership and trash rules also apply
to the whole selection. Successful actions dispatch Filament's standard
selection-clear event.

For a host table mapping to other models, provide a preloaded relation or
attributes to `entry()` so resolving selected records does not issue one extra
query for each item. `afterEntries(fn (array $entries) => ...)` receives updated
Studio rows after a successful bulk mutation.

## Select or upload a file from a button

Provide the browser bundle and stylesheet URLs published by your application.
The picker uses the shared Studio transfer queue, chunk configuration and
workspace permissions.

```php
use UseFlex\MediaStudio\Integrations\Filament\Actions\SelectFileAction;

public function coverAction(): SelectFileAction
{
    return SelectFileAction::make('cover')
        ->workspace($this->workspaceId)
        ->assets('/assets/media-studio/studio.js', '/assets/media-studio/studio.css')
        ->label('Choose or upload cover')
        ->onSelect(function (array $reference): void {
            $this->data['cover_asset_id'] = $reference['assetId'];
            $this->data['cover_version_id'] = $reference['versionId'];
        });
}
```

The submitted asset/version pair is resolved again with current workspace and
source access before the callback runs. Without `onSelect()`, the action
dispatches `media-studio-file-selected` with a `reference` payload. Save
application usage references in the same transaction as your owning record.
See [library references and configuration](/configuration).

For the native MarkdownEditor or RichEditor toolbar, use the dedicated
[editor adapters](/filament-editors).


## Individual actions

### CreateFolderAction

Choose the workspace root or an authorized managed folder as the parent. The action validates the name and runs with the current workspace write permissions.

```php
use UseFlex\MediaStudio\Integrations\Filament\Actions\CreateFolderAction;

CreateFolderAction::make()
    ->workspace($workspaceId)
    ->label('New project folder')
    ->afterEntry(function (object $entry): void {
        $this->dispatch('studio-folder-created', folder: $entry->public_id);
    });
```

Add the action to a page header or render it as a Livewire action. Its folder picker searches managed folders. The callback receives the created Studio row.


### RenameAction

The dialog starts with the current name. The confirmation binds the entry revision. If another user renames or moves that entry before submission, reopen the dialog with fresh data.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RenameAction;

RenameAction::make()
    ->workspace($workspaceId)
    ->authorize(fn ($record): bool => Gate::allows('update', $record));
```

Register the action in `recordActions()`. Map the record to a Studio public ID and positive integer revision, either through native attributes or the `entry()` callback. Use `afterEntry()` to refresh application state after the mutation succeeds.


### MoveAction

Choose a destination folder or the workspace root. The server validates the current folder hierarchy and rejects a move into the entry’s own subtree.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\MoveAction;

MoveAction::make()
    ->workspace($workspaceId)
    ->authorize(fn ($record): bool => Gate::allows('update', $record));
```

Register the action in `recordActions()`. Map the record to a Studio public ID and positive integer revision, either through native attributes or the `entry()` callback. Use `afterEntry()` to refresh application state after the mutation succeeds.


### TrashAction

Use this action for reversible removal. The Studio trash policy applies to folders and files. Permanent deletion is managed separately.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\TrashAction;

TrashAction::make()
    ->workspace($workspaceId)
    ->authorize(fn ($record): bool => Gate::allows('delete', $record));
```

Register the action in `recordActions()`. Map the record to a Studio public ID and positive integer revision, either through native attributes or the `entry()` callback. Use `afterEntry()` to refresh application state after the mutation succeeds.


### RestoreAction

Use this action on a records query for trashed Studio entries. Studio checks restore rights and applies its current destination and collision rules.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RestoreAction;

RestoreAction::make()
    ->workspace($workspaceId)
    ->authorize(fn ($record): bool => Gate::allows('restore', $record));
```

Register the action in `recordActions()`. Map the record to a Studio public ID and positive integer revision, either through native attributes or the `entry()` callback. Use `afterEntry()` to refresh application state after the mutation succeeds.


### DownloadAction

The action resolves the mapped asset’s current version and redirects to Studio’s authorized download URL. The download endpoint checks permission when the file is requested.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\DownloadAction;

DownloadAction::make()
    ->workspace($workspaceId)
    ->authorize(fn ($record): bool => Gate::allows('view', $record));
```

Register the action in `recordActions()`. Map the record to a Studio public ID and positive integer revision, either through native attributes or the `entry()` callback.


### SelectFileAction

The modal resolves one pinned file reference. The upload capability is governed by the workspace and user. Use the low-level StudioPicker when your action needs custom selection controls.

```php
use UseFlex\MediaStudio\Integrations\Filament\Actions\SelectFileAction;

SelectFileAction::make()
    ->workspace($workspaceId)
    ->assets($moduleUrl, $stylesheetUrl)
    ->onSelect(function (array $reference): void {
        $this->data['cover'] = \UseFlex\MediaStudio\Integrations\Filament\StudioReference::key($reference);
    });
```

The callback receives the authorized asset and version, name, MIME type and URL. Without the callback, the action dispatches `media-studio-file-selected` with a `reference` payload. Save the host record and register its usage when the selection is committed.


## Bulk actions

### MoveBulkAction

Choose one authorized target folder for the entire selection. The folder hierarchy and each entry revision are checked before the move.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\MoveBulkAction;

MoveBulkAction::make()
    ->workspace($workspaceId)
    ->authorizeIndividualRecords(fn ($record): bool => Gate::allows('update', $record))
    ->afterEntries(function (array $entries): void {
        $this->dispatch('studio-entries-changed', count: count($entries));
    });
```

Register the action in `toolbarActions()`. Use an authorized table query and explicit row selection, up to 100 entries. A denied, missing or stale entry rejects the bulk operation. The callback receives the updated Studio rows.


### TrashBulkAction

The action applies reversible trash semantics to the selection. Authorization and source restrictions apply to every item.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\TrashBulkAction;

TrashBulkAction::make()
    ->workspace($workspaceId)
    ->authorizeIndividualRecords(fn ($record): bool => Gate::allows('delete', $record))
    ->afterEntries(function (array $entries): void {
        $this->dispatch('studio-entries-changed', count: count($entries));
    });
```

Register the action in `toolbarActions()`. Use an authorized table query and explicit row selection, up to 100 entries. A denied, missing or stale entry rejects the bulk operation. The callback receives the updated Studio rows.


### RestoreBulkAction

Use a table showing trashed records. Each entry must still be eligible for restore when the action runs.

```php
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Integrations\Filament\Actions\RestoreBulkAction;

RestoreBulkAction::make()
    ->workspace($workspaceId)
    ->authorizeIndividualRecords(fn ($record): bool => Gate::allows('restore', $record))
    ->afterEntries(function (array $entries): void {
        $this->dispatch('studio-entries-changed', count: count($entries));
    });
```

Register the action in `toolbarActions()`. Use an authorized table query and explicit row selection, up to 100 entries. A denied, missing or stale entry rejects the bulk operation. The callback receives the updated Studio rows.
