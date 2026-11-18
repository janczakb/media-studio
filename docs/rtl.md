---
title: "Right-to-left layouts"
description: "Enable locale-driven direction and understand what RTL support does and does not guarantee."
---

**Studio implements RTL direction support.** It derives direction from the selected
locale through HeroUI's `isRTL`, sets `lang` and `dir` on its own interface/portal
root, and provides the locale to HeroUI's `I18nProvider`. It does not require
changing the direction of the entire host application.

## Mount an Arabic instance

```tsx
import {MediaStudio, type StudioLocaleCatalog, type StudioTransport}
  from '@useflex/media-studio';

const arabic = {
  label: 'العربية',
  messages: {
    upload: 'رفع الملفات',
    newFolder: 'مجلد جديد',
    search: 'البحث بالاسم…',
    retry: 'إعادة المحاولة',
  },
} satisfies StudioLocaleCatalog;

const localization = {catalogs: {ar: arabic}};

export function ArabicLibrary({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} locale="ar" localization={localization} />;
}
```

The example supplies a partial Arabic dictionary. The layout's direction is RTL,
but missing labels remain English. Register a full catalog using
[custom translations](/custom-translations) for a localized interface. Hebrew and
other RTL locales use the same locale-driven mechanism. `StudioOptions` does not
currently expose a separate `direction` override.

## Direction-aware behavior in the implementation

| Surface | RTL behavior |
| --- | --- |
| Studio instance and its portals | Root `lang` and `dir` follow the selected locale |
| HeroUI controls | Receive the instance locale through `I18nProvider` |
| Folder tree | Expand/collapse arrow-key interpretation follows computed direction; closed disclosure arrows are mirrored |
| File preview | Previous/next arrow icons and keyboard navigation account for direction |
| Emoji picker | Receives locale-specific direction explicitly |
| Trim timeline and share analytics | Include direction-specific playhead/value/navigation styling |

## Host components and mixed content

The host owns Filament/FLEX Fields direction outside the Studio root. Configure
the host's locale/direction independently. Third-party preview engines, chart axes,
code blocks and text inside the uploaded file can have their own direction.
Selecting Arabic does not mirror image pixels or rewrite a PDF.

Filenames, email addresses, IDs and URLs often mix scripts. In host-authored UI,
isolate them with semantic bidi elements rather than inserting invisible control
characters into stored names:

```html
<p dir="rtl">الملف: <bdi dir="auto">launch-2026.pdf</bdi></p>
```

## Support boundary and review

RTL direction support and a complete RTL-language translation are different.
EN/PL are the shipped dictionaries. Catalog-backed localization has
[known surface limitations](/custom-translations#loading-retries-and-current-limits).
The current implementation is not a certification of every editor/codec/provider
surface for Arabic or Hebrew.

When shipping a custom RTL locale, review search/popovers, deep folder navigation,
selection and preview actions, focus order, keyboard arrows, dialogs, captions,
numeric inputs and embedded host fields in both themes and at mobile width.
Keep your own translated labels in that review; unusually long translations can
expose layout issues that English does not.
