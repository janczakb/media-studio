---
title: "Translate Laravel and Filament labels"
description: "Override package translations in your application and keep PHP labels separate from the React catalog."
---

Studio registers the Laravel translation namespace `media-studio`. It ships
`editor.php`, `actions.php`, `permissions.php` and `push.php` catalogs in English
and Polish. Filament field/action labels resolve through these files; translating
them does not translate the embedded React library.

## Override a package label

Create `lang/vendor/media-studio/de/editor.php` under your application's configured
Laravel language directory:

```php
<?php

return [
    'upload_file' => 'Datei hochladen',
    'choose_files' => 'Medien auswählen',
    'pick_from_library' => 'Dateien aus Media Studio auswählen',
    'remove_file' => 'Datei entfernen',
    'download_file' => 'Herunterladen',
    'file_count' => 'Wählen Sie zwischen :min und :max Dateien.',
];
```

Create the corresponding `actions.php` when needed:

```php
<?php

return [
    'create_folder' => 'Neuer Ordner',
    'rename' => 'Umbenennen',
    'move' => 'Verschieben',
    'trash' => 'In den Papierkorb',
    'restore' => 'Wiederherstellen',
    'download' => 'Herunterladen',
];
```

Use `lang_path('vendor/media-studio/de/editor.php')` to resolve the actual path
when the host customizes its language directory. The package currently has no
`media-studio-translations` publish tag: create your override files explicitly or
copy the matching shipped catalogs into your host language directory. Do not edit
package/vendor files.

## Set the request locale

Your host's locale middleware should select the locale for the current request.
For an isolated example:

```php
use Illuminate\Support\Facades\App;

App::setLocale('de');
$label = __('media-studio::editor.choose_files');
// Medien auswählen
```

Use a request-scoped locale in persistent workers. Configure Laravel's fallback
locale for missing translations. Keep PHP locale selection and the embedded
React `locale`/`localization` configuration aligned when your host has a language
switcher. Setting only `App::setLocale()` does not register a JavaScript catalog.

## Permission and notification copy

Translate permission labels in `permissions.php` without changing the stable
Spatie ability names. `permissions.labels` is the separate supported configuration
override for permission-editor display labels. Never translate permission IDs.

Translate package push strings in `push.php`; browser permission prompts and
operating-system notification chrome belong to the browser/OS. Preserve tokens
such as `:min`, `:max` and `:limit` exactly. FLEX Fields' own upload/editor strings
remain owned by FLEX Fields; the Studio namespace covers Studio-specific adapter
labels.

## Translatable host content

You may use your own translatable Eloquent attributes around a Studio field.
Studio does not automatically translate a model, a file's alternative text,
caption, OCR text or document contents. Keep localized host content in the model's
translation workflow and use durable [media references](/media-references).
[Tags](/tags) carry an explicit locale; changing interface language does not
create translated copies of them.
