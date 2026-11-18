---
title: "Languages and translatable interfaces"
description: "Choose a locale, add your own catalog and understand Laravel, tag languages and RTL."
---

Studio has an instance-level interface catalog, Laravel package translation files,
and locale-labelled tags. These are separate from translating the content of an
uploaded document. Built-in interface and Laravel catalogs currently ship in
**English and Polish**. Additional languages are application-provided catalogs.

| What you want to translate | Configure |
| --- | --- |
| Studio React interface and dialogs | `StudioOptions.locale` and `localization.catalogs`; [custom translations](/custom-translations) |
| Filament field/action labels and package notification strings | Laravel `media-studio::` translation namespace; [Laravel translations](/laravel-translations) |
| Right-to-left interaction | Locale-driven direction; [RTL support](/rtl) |
| Tag labels in different languages | Explicit tag locale and `browse.tag_locales`; [tagging](/tags) |
| A host model using Spatie Translatable | The host model owns its translations; Studio stores/resolves media references independently |
| Text inside PDFs, captions or AI responses | Not translated automatically by selecting the interface language |

## Set an initial interface language

```tsx
import {MediaStudio, type StudioTransport} from '@useflex/media-studio';

export function Library({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} locale="pl-PL" />;
}
```

Locales use BCP 47 identifiers such as `pl-PL`, `de-DE` or `ar`. Underscore input is
normalized before canonicalization. On initial mount Studio tries the device's
saved preference, the supplied `locale`, the document language and browser
languages, then English. A saved device preference can therefore take precedence
over the initial prop. A later prop change requests the new locale. Do not mutate
Studio's private localStorage format as a localization API.

Workspace/device preference behavior is described in [language and timezone
preferences](/workspace-preferences#language-and-timezone-preferences). Locale and
time zone are independent: changing language does not change a file timestamp.

## Lazy language loading

Studio resolves regional/script catalogs and the base language, then uses English
for omitted keys. A custom `de-DE` catalog can override `de`; neither requires
editing package source. Invalid non-string overrides do not replace valid fallback
strings. A selected language does not imply every surface has been translated.

Shipped catalogs are separate `studio-locale-<code>.js` assets. Emoji datasets use
`emoji-data-<code>.js` for the 28 languages included with the pinned emoji package;
those datasets are fetched only when needed, not when opening the library. Serve
the complete built asset directory rather than copying only `studio.js`.

See [custom translations](/custom-translations) for static overrides, lazy modules,
typed complete catalogs, placeholders and load-failure behavior. See [RTL](/rtl)
for direction, mixed-script filenames and the current support boundary.
