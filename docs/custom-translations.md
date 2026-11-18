---
title: "Create a custom Studio translation"
description: "Add a language without forking Studio, override individual messages and validate your catalog."
---

Use the exported `StudioLocaleCatalog` type. Catalogs belong to your application,
so package updates do not overwrite them. The same `localization` object works with
`MediaStudio` and `mountMediaStudio`.

## Override a few messages

```tsx
import {MediaStudio, type StudioLocalization, type StudioTransport}
  from '@useflex/media-studio';

const localization = {
  catalogs: {
    en: {
      label: 'English',
      messages: {upload: 'Add files', newFolder: 'Create folder'},
      namespaces: {ai: {labels: 'Suggested labels'}},
    },
  },
} satisfies StudioLocalization;

export function Library({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} locale="en" localization={localization} />;
}
```

Keep catalog/loader objects stable outside the component or memoize them. The SDK
shares successful lazy loads by loader identity; recreating loader functions on
every render defeats that reuse.

## Add a language as a lazy module

Create `translations/de.ts` in your application:

```ts
import type {StudioLocaleCatalog} from '@useflex/media-studio';

export default {
  label: 'Deutsch',
  messages: {
    upload: 'Dateien hochladen',
    newFolder: 'Neuer Ordner',
    search: 'Nach Namen suchen…',
    loading: 'Bibliothek wird geladen…',
    retry: 'Erneut versuchen',
  },
  namespaces: {
    ai: {labels: 'KI-Vorschläge', accept: 'Annehmen', reject: 'Ablehnen'},
  },
} satisfies StudioLocaleCatalog;
```

Register it in the host bundle:

```tsx
import {MediaStudio, type StudioLocalization, type StudioTransport}
  from '@useflex/media-studio';

const localization = {
  bootstrap: {loading: 'Wird geladen…', retry: 'Erneut versuchen'},
  catalogs: {
    de: {
      label: 'Deutsch',
      load: () => import('./translations/de').then(module => module.default),
    },
  },
} satisfies StudioLocalization;

export function GermanLibrary({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} locale="de-DE" localization={localization} />;
}
```

This is intentionally a **partial** German catalog: untranslated keys fall back
to English. Your bundler owns the lazy module URL; Studio does not build an import
path from untrusted locale input. Labels expose registered languages in the
language selector. Only relevant language loaders run.

## Understand catalog sections

| Section | Translates |
| --- | --- |
| `messages` | Main library, search, previews, upload controls and common actions |
| `workspace` | Workspace dialogs, roles, member access and folder/navigation copy |
| `namespaces.management` | Management surfaces and share analytics |
| `namespaces.ai` | AI label review, search and AI-client setup surfaces |
| `namespaces.integrations` | Browser-extension approval and connected-app surfaces |
| `label` | Language name in the selector |
| `load` | Async provider returning any of the catalog sections except another loader |
| `bootstrap.loading`, `bootstrap.retry` | Startup text before a catalog is available; these are on `StudioLocalization`, not inside a catalog |

Preserve interpolation tokens exactly. Workspace interpolation uses `{name}`-style
tokens; Laravel uses `:name`, and plural/count behavior belongs to the specific
consumer. Translate surrounding text rather than renaming tokens. Keep labels as
strings, not HTML or React elements.

## Require a complete catalog in your application

The public API permits partial overrides. To make missing keys a compiler error
for your own translation module, declare a stricter type:

```ts
import type {StudioLocaleCatalog, StudioNamespaces, WorkspaceMessages}
  from '@useflex/media-studio';

export type CompleteStudioCatalog = {
  label: string;
  messages: Required<NonNullable<StudioLocaleCatalog['messages']>>;
  workspace: WorkspaceMessages;
  namespaces: StudioNamespaces;
};
```

Apply `satisfies CompleteStudioCatalog` to your full dictionary. Use the matching
package version's English catalogs as the key inventory: main
`packages/react/src/locales/en.ts`, workspace
`packages/react/src/locales/workspace/en.ts` and key manifest `packages/react/src/studio-locales.ts`, and each component namespace's `locales/en.ts`.
These source files are an authoring reference, not public deep-import paths.
The exported types provide the supported validation surface.

## Loading, retries and current limits

A loaded dictionary is committed together, keeping the library/chat instance
mounted. Optional custom-loader failures fall back to the shipped catalog; a
required English catalog failure exposes startup retry behavior. Failed custom
loads are evicted from the loader cache and can run again on a subsequent load.

The schema covers catalog-backed copy. Some assistant and specialized editor text
currently contains direct English/Polish branches, so completing every catalog
key does **not** prove that every visible sentence in every integration has been
translated. Check those surfaces when adding a language. Laravel labels and
FLEX Fields' own labels have their own namespaces. See [Laravel translations](/laravel-translations)
and [RTL validation](/rtl).
