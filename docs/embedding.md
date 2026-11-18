---
title: "Embed and customize Studio"
description: "Mount the React interface and configure branding, navigation and viewer controls."
---

Start with a viewer and transport in your host application. Then enable the surfaces relevant to that installation.

## Branding and embedding

```ts
import {mountMediaStudio, createHttpTransport} from '@useflex/media-studio';

const studio = mountMediaStudio(document.querySelector('#media-library'), {
    transport: createHttpTransport({
        baseUrl: '/media-studio/api/v1',
        csrfToken: () => document.querySelector('meta[name="csrf-token"]').content,
    }),
    stylesheetUrl: '/assets/studio.css',
    theme: 'light',
    locale: 'en',
    user: {name: 'Alex Editor', avatarUrl: '/avatars/alex.webp'},
    onThemeChange: theme => console.log('Selected appearance:', theme),
    branding: {logoUrl: '/brand.svg', logoLabel: 'My application'},
});

// Dispose when the host page or dialog is removed.
studio.destroy();
```

The default mark is the original FLEX Media Studio logo. Branding can be replaced without
installing Filament. The interface owns its CSS and overlays; the host still owns
authentication and the API's authorization rules.

The top bar contains search and the account menu with light/dark/system appearance. `user` supplies display information
only: it does not authenticate the visitor or grant access. Omit `avatarUrl` to
use a green avatar with the first letter of the user name. `onThemeChange` can save the selected
appearance in your application. Each mounted Studio keeps its own appearance.

The library offers Grid, Large grid and List layouts through its Layout menu.
Folder breadcrumbs appear below the title after opening a folder. Date filters
use the keyboard-accessible HeroUI calendar; dates are sent as inclusive UTC
calendar dates. All browse filters and sort options continue to apply to child
folders. The displayed loaded-item count describes the bounded browser window,
not the total number of files in your database.

On initial opening, Studio waits for its stylesheet, workspace permissions,
first listing response and measured layout before revealing the interface.
A centered loading indicator is shown while these are prepared. Searching and
navigating after startup keep the top search field mounted.

## Preview action bar

Configure the preview actions when mounting Studio. Add a separator before an action with `separatorBefore`:

```ts
previewActions: [
  {id: 'download'},
  {id: 'trim'},
  {id: 'draw'},
  {id: 'annotations'},
  {id: 'trash', separatorBefore: true},
  {id: 'comments', separatorBefore: true},
],
```

Download appears first when permitted; Comments appears last after a separator and opens the comments panel. Both actions respect configuration and permissions. Actions adapt to the file type: trimming appears for supported videos, while drawing and overlay visibility apply to images. Drawing requires write permission and a readable image. For indexed Spatie images, drawings are stored as a separate Studio overlay; the source image is not modified. The overlay visibility action appears only when a drawing exists. The Draw action keeps its place while image details load, so switching between images with the same actions does not resize the bar. Set `previewActions: false` to hide the bar entirely.

The bar changes width when its visible controls change. Replacing actions in an existing slot animates the icons separately. Reduced-motion preferences disable these transitions.

In drawing mode, click the image with the Text tool to type in place. Press Enter
to finish, Shift+Enter for another line, or Escape to discard the current text
edit. Click existing text with the Text tool to edit it again. With Select, click once to select it and again to edit; double-click also opens editing. Drag a selected object to
move it; vector objects expose corner handles for resizing. Changing the palette
color updates the selected object. Keyboard shortcuts: V Select, D Pen, E Eraser,
A Arrow, T Text, R Rectangle, O Ellipse, L Line; Ctrl/Cmd+Z undoes the last edit and
Ctrl/Cmd+Shift+Z restores it.

The Styles panel adapts to the active tool or selected object. It provides color
and opacity; drawing tools add stroke size, solid/dashed/dotted lines, and fill
where applicable. Text adds size, system font family and horizontal alignment.
Rectangles and ellipses support labels (double-click to edit), including vertical
alignment, with no change to the source image. Styles are saved with each mark.

## Configure navigation and page panels

The React interface uses one shared layout. You can hide navigation destinations,
choose which pages have a contextual panel, and provide your own panel content.
These display settings never grant permissions: the API continues to enforce
access to each workspace and operation.

```tsx
import {MediaStudio, type StudioLayoutOptions} from '@useflex/media-studio';
import {Button} from '@heroui/react';

const layout: StudioLayoutOptions = {
  panelWidth: 248,
  navigation: {
    items: ['library', 'workspaces', 'members', 'shares', 'notifications', 'ai'],
  },
  views: {
    ai: {panel: false},
    shares: {panel: false},
    photos: {panel: 'library', panelWidth: 260},
    notifications: {
      panel: {
        id: 'notification-help',
        label: 'Notification tools',
        render: ({workspace, navigate, close}) => (
          <div className="p-4">
            <p>{workspace?.name}</p>
            <Button onPress={() => {navigate('files'); close();}}>Back to files</Button>
          </div>
        ),
      },
    },
  },
};

<MediaStudio transport={transport} layout={layout} syncUrl />;
```

Omit a page entry to keep its default layout. `panel: false` removes the contextual
panel, `panel: 'library'` uses built-in navigation, and `panel: 'workspaces'` shows
the workspace selector. Custom panel content should be a React component; its
render callback receives the current view, workspace, locale, `navigate()` and
`close()`. Panel widths are bounded to 200–400 pixels. On narrow screens the panel
opens over the content, preserving room for the library.

`navigation: false` hides the primary icon rail. `header: false` on a page removes
the shared search/account header. When a panel is enabled without the header, Studio keeps a compact mobile
opening control so the panel remains reachable. Picker mode always uses the dedicated navigation-free
layout, regardless of page settings.

## Document previews

The React library and editor picker can preview DOCX, XLSX, PPTX, Pages, Keynote,
Numbers, DOC, XLS, PPT, ODT, ODS, ODP, RTF, CSV and Markdown. Readers load only
when a file is opened. Documents are read-only and are processed in a browser
worker; this does not save a second PDF or replace the original file.

```ts
mountMediaStudio(element, {
  transport,
  documentPreview: {
    // Omit formats to enable all supported formats.
    formats: ['docx', 'xlsx', 'pptx', 'csv', 'md'],
    outline: false,        // Hide the document outline button (default)
    search: true,          // Header search action (default)
    maxBytes: 50 * 1024 * 1024,
    timeoutMs: 60_000,
    residentCells: 250_000, // CSV cells retained in memory
    pageRows: 512,         // CSV rows read together beyond that budget
  },
});
```

Set `documentPreview: false` to disable these readers. Upload acceptance remains
an independent server setting; enabling a preview does not authorize an upload
or grant access to a file. A known original size is required for the size check.

Host `office-preview.js`, `office-preview.css`, `office-engine.worker.js` and
`office-engine-LICENSE.txt` alongside `studio.js`. Allow that worker URL in your
Content Security Policy. These assets are not fetched for grid thumbnails.
Authenticated content URLs retain the transport's authorization. A separate CDN
must permit CORS and byte-range requests, including exposed `Content-Range`;
without byte ranges the reader may download the entire allowed file.

A thin bar at the top reports download or parsing progress when the engine
provides a total; other stages use an indeterminate animation. It fades away
when content is ready. A failed or timed-out opening offers retry. Closing or
changing the file stops its worker.

This development checkout pins an upstream Office Engine pre-release to support
the legacy formats, which were not included in npm 0.11.1. Formatting coverage
depends on the reader: encrypted files, older iWork versions and some advanced
Office features may not render. File-size and time limits do not guarantee a
fixed decompressed memory footprint for arbitrary documents.

The header search button opens a compact Find field and highlights matching text.
Click it again to close the panel. The field's clear button removes the query;
next/previous arrows and Enter/Shift+Enter navigate the results. Ctrl/Cmd+F opens
Find and Escape closes it. Search works in text documents and in the active
worksheet of a spreadsheet; presentation search is not currently available.
The panel overlays the document without resizing its viewport. Set
`documentPreview.search: false` to remove the action, or `outline: true` to
restore the reader's document-outline toggle.

## Primary color and viewer profile

```php
// config/media-studio.php
'appearance' => ['primary_color' => '#0866ff'],
```

Use a six-digit hexadecimal color. Native workspace discovery returns this
configuration and the current authenticated profile to Studio; components and
portals inherit the same instance accent. Foreground is chosen as black or white
for contrast. React `primaryColor: '#b51b43'` overrides the server default per
mounted instance. Filament pickers receive the configured primary color too.
Colors inside document content and semantic success/error states are preserved.

The account menu shows avatar, name and email. Its Appearance row places the
label left and compact HeroUI tabs right. An explicit React `user` profile
overrides the profile returned by the native transport; this is display data
only and never establishes authentication.

## Keyboard and clipboard controls

See [keyboard shortcuts](/keyboard-shortcuts) for per-instance commands, editable bindings and clipboard upload configuration.
