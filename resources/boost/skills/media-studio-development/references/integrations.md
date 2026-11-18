# Integration contracts

## React

The frontend module is `@useflex/media-studio`. Public exports include
`MediaStudio`, `mountMediaStudio`, `createHttpTransport`, `StudioOptions`,
`StudioTransport`, `MediaReference`, `StudioLocalization` and shortcut types.
A mount returns `{destroy()}`; destroy before replacing the host DOM. Provide
matching `studio.js`, `studio.css` and their lazy assets from the same build.

```tsx
import {MediaStudio, type StudioTransport} from '@useflex/media-studio';
export function Library({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} locale="en" theme="light"
    shortcuts={{bindings:{search:'Mod+K'},clipboard:{paste:true,copyImages:true}}} />;
}
```

`mode:'picker'` uses configured MIME/selection/folder restrictions. Inspect
`StudioOptions` for the installed exact option names. Folder navigation remains
available when only image files are selectable. Client filtering is presentation;
PHP must enforce the field policy on resolve/upload/save too.

## Native Filament

Use `UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker`:

```php
StudioMediaPicker::make('gallery')
    ->studio($workspaceId, '/studio-assets/studio.js', '/studio-assets/studio.css')
    ->multiple()
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->minFiles(1)
    ->maxFiles(6)
    ->layout('grid')
    ->layoutSwitcher(false)
    ->downloadable(false);
```

Also available: `StudioMediaColumn`, `StudioMediaEntry` in the same namespace.
RichEditor/MarkdownEditor and native Filament FileUpload have their own adapters;
read `/filament-editors`, `/filament-file-upload`, `/filament-configuration` and
`/filament-actions` on the docs site. Do not replace a native Filament upload just
to use Studio or guess the namespace from another uploader package.

Inspect `Integrations/Filament/Concerns/InteractsWithStudioUploads.php` and the
actual field for current folder scope, upload enablement, count/type constraints,
preview, thumbnail preset, custom actions and persistence options.

## Optional FLEX Fields

Use the FLEX package's own factory when installed:
`Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload::mediaStudio()`.
It selects Studio's `Integrations\FlexFields\StudioFileUpload` adapter. Keep this
integration under that boundary; Studio must still work without FLEX Fields.
Preserve native FLEX Fields asset loading, file list and per-file actions.

AI/classic image editing requires the host's supported generation/publication
callbacks. Do not invent a one-call `saveAiVersion()` helper. Follow
`/flex-fields/image-editing` and `/media-versions`, inspect the current adapter
methods, and verify accepted bytes produce the configured thumbnail. Reference
selection does not download/re-upload existing library objects.

## Localization

React catalogs are typed `StudioLocaleCatalog` objects, with `messages`,
`workspace`, and `namespaces.management/ai/integrations`. Register lazy loaders
through `localization.catalogs`; missing keys fall back to English. `locale`
controls RTL direction, but EN/PL are the bundled dictionaries. Some specialized
surfaces still have direct EN/PL copy; do not claim total language coverage.
Laravel translations use `media-studio::` and host files under
`lang/vendor/media-studio/{locale}`. They do not register JS catalogs.
