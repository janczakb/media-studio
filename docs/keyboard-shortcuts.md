---
title: "Keyboard shortcuts and clipboard"
description: "Record custom shortcuts, resolve conflicts and paste clipboard files into the open folder."
---

Open **Settings → Keyboard shortcuts** to view and change the bindings for your
current device. The recorder displays physical key combinations with platform
labels, animates held modifiers, offers reset/clear actions and asks before taking
a chord already assigned to another action. The searchable grouped reference
highlights matching keys as you hold them.

## Default commands

`Mod` means Command on macOS and Ctrl on Windows/Linux.

| Command | Default | Availability |
| --- | --- | --- |
| Search | `Mod+K` | Opens Studio search |
| Upload files | `Mod+Shift+U` | Writable file/photo view with uploads enabled |
| New folder | `Alt+Shift+N` | Writable folder view and folder-create permission |
| Settings | `Alt+Shift+S` | Opens Studio settings |
| Select loaded items | `Mod+A` | Current loaded selection window, within its selection limit |
| Clear selection | `Escape` | A selection exists and no dialog/preview owns the key |
| Copy selected image | `Mod+C` | One supported selected image with known bounded dimensions and download access |
| Paste files | `Mod+V` | Writable destination with uploads enabled |

These are in-application shortcuts, not OS-wide hotkeys. A command acts only in
the active Studio instance. Text inputs, editable content, open dialogs, menus and
listboxes retain their own keyboard behavior. Repeated held keys, IME composition
and AltGraph input do not repeatedly execute commands.

## Mac / Windows preview

The settings header includes Mac and Windows tabs, initially selected from the device platform. Switching them updates the keycap labels, spoken combinations and recorder modifier interpretation, as in the Arc recorder. It does not change your operating system or the platform used by command dispatch. `Mod` remains Command on an Apple device and Ctrl elsewhere.

## Record or replace a shortcut

Click the recorder, then press a combination. Live modifier keycaps appear while
you build it. Escape cancels recording; Backspace/Delete clears the binding; Tab
leaves the recorder. Ordinary action bindings require Command/Ctrl/Alt or a
function key; Clear selection also permits an unmodified key.

If another command owns the chord, **Use anyway** transfers the binding and
disables the previous assignment in one preference update. Cancel keeps the old
binding. Browser-reserved combinations show a warning: accepting one cannot make
an OS/browser-reserved key available to the web page. Prefer defaults or a
combination your browser actually delivers. Reset restores the application's
baseline for that action; **Restore all defaults** clears device overrides.

Preferences use a versioned local device record scoped to the configured Studio
user email (or the browser fallback). They are not a team policy or server-stored
profile. Browser storage can be unavailable; the current instance still works,
but changes may not survive reload. Application configuration can disable user
customization or the complete subsystem.

## Paste a screenshot or file into a folder

1. Copy an actual image/file into the system clipboard.
2. Open the target folder in Studio and activate the library.
3. Press Command+V or Ctrl+V outside a text field.

Studio reads files from the trusted paste event and sends them to the existing
upload pipeline with the captured workspace/folder destination. Upload permission,
accepted type, field scope, server limits and transfer progress still apply.
Generated screenshot names include a timestamp and unique suffix. Normal clipboard
text is not turned into a file and remote image URLs are not fetched automatically.

The browser/OS must expose file bytes. Copying an image URL or a Finder/Explorer
file reference may not expose the same data as copying image pixels; use the
ordinary upload picker when no file is delivered. A custom Paste shortcut uses
`navigator.clipboard.read()` and can require HTTPS, browser permission or a native
paste prompt. The standard Mod+V path uses the paste event and does not read the
clipboard in the background.

## Copy an image from Studio

Select one JPEG, PNG, WebP or AVIF image with known dimensions, then use the Copy
shortcut. Studio requests authorized current-version bytes, checks bounded input
and dimensions, converts to PNG and writes the image to the system clipboard.
Download authorization and browser clipboard support are required. SVG and unknown
or oversized dimensions do not use this copy path. A denied/unsupported operation
shows an error rather than claiming success. Ordinary text selection keeps native
text-copy behavior.

Copying arbitrary files or folders between library destinations is not implemented
by this clipboard feature. Pasting image bytes creates a new uploaded asset; it
is separate from reusing an existing [media reference](/media-references).

## Configure the SDK

```tsx
import {MediaStudio, type StudioTransport} from '@useflex/media-studio';

export function Library({transport}: {transport: StudioTransport}) {
  return <MediaStudio transport={transport} shortcuts={{
    enabled: true,
    customizable: true,
    bindings: {search: 'Mod+K', upload: 'Mod+Shift+U'},
    clipboard: {
      paste: true,
      copyImages: true,
      maxFiles: 20,
      maxBytes: 25 * 1024 * 1024,
      maxPixels: 16_000_000,
    },
  }} />;
}
```

| Option | Default / effect |
| --- | --- |
| `shortcuts: false` | Disables commands and hides the settings section |
| `enabled` | Defaults true; false disables dispatch |
| `customizable` | Defaults true; false ignores stored overrides and locks the recorder |
| `bindings` | Partial command-ID map; null disables a command |
| `clipboard: false` | Disables clipboard actions independently of other commands |
| `clipboard.paste` / `.copyImages` | Default true |
| `clipboard.maxFiles` | Default 20; clamped 1–120 |
| `clipboard.maxBytes` | Default 25 MiB; clamped 1–256 MiB |
| `clipboard.maxPixels` | Copy-to-PNG decode guard: default 16 MP; clamped 1–40 MP |

Pasted files are not decoded merely to admit an upload; image-processing pixel limits remain enforced by the configured server pipeline. The clipboard budget supplements the existing upload policy; increasing it does
not raise server upload limits. Native pasted files are admitted as a batch before
transfer. Invalid/empty or oversized clipboard batches are rejected with a message.
The registry normalizes physical keys and executes at most one command for a
binding; custom host actions should use the exported `ShortcutRegistry` contract
without installing competing document listeners.
