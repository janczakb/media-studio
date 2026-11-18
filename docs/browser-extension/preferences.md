---
title: "Preferences and shortcuts"
description: "Configure drag behavior, screenshot output, source permissions, shortcuts, language and local limits."
---

Open **Preferences** from the extension popup. Preferences are stored in the browser's extension storage and affect that browser profile. Studio's storage, permissions and upload policy are configured on the server.

## Collection settings

| Setting | Default | Behavior |
| --- | --- | --- |
| Drag feature | Disabled | Choose Popup, Bottom or Disabled |
| Ask where to save | On | Review folder and tags in eligible save workflows |
| Download through the browser | Off | Include the current website's session for eligible same-origin downloads |
| Prefer high-resolution images | On | Prefer larger sources exposed by the page or supported site metadata |
| Alt + right-click capture | On | Enable the separate quick-capture gesture on permitted pages |

The popup also lets you enable drag on the current website, disable it for the exact page, or disable it for the domain. Exclusions and browser host grants both affect whether the page workflow is active.

The high-resolution preference can choose a larger responsive source, explicit image link or supported site variant. The downloaded file must still satisfy the source and Studio policies. Where supported, an unavailable larger source can fall back to another exposed image.

## Website access and browser session

Under **Websites and connections**, choose **Allow on this website** for a scoped host grant or **Allow on all websites** for broader browser access. Studio access is granted separately by [Connect Studio](/browser-extension/install#approve-a-workspace).

A page and its asset host can differ. Saving an image hosted on a CDN can require a grant for that CDN as well. Browser internal pages, extension pages and some protected store pages restrict injection or capture.

**Download through the browser** applies the browser's session to file requests only when the file and source page have the same origin. Ordinary source downloads omit credentials. A source on another origin does not receive the page's session through this option. Browser cookie policies and server authorization still determine whether a request succeeds.

## Screenshot settings

| Setting | Default | Choices |
| --- | --- | --- |
| Format | PNG | PNG or JPEG |
| Retina resolution | On | Preserve browser pixel density when available, or use CSS-pixel scale |
| Scroll delay | 0.5 seconds | 0.5–3 seconds between full-page capture positions |

Use PNG for sharp interface details. JPEG can reduce the size of photographic captures. Increase the scroll delay when the page needs more time to finish loading each viewport. The output is still subject to the [capture bounds](/browser-extension/capture#save-screenshots).

## Record shortcuts

Click the action's shortcut field and press a modifier with a letter or digit. Ctrl, Alt, Shift and Meta combinations are supported, with Ctrl, Alt or Meta required. Delete or Backspace clears a binding. Duplicate bindings are rejected. Tab and Escape retain their usual field behavior.

| Action | Default page shortcut |
| --- | --- |
| Save URL | Alt + 0 |
| Batch save | Alt + 1 |
| Capture area | Alt + 2 |
| Capture visible | Alt + 3 |
| Capture page | Alt + 4 |

On macOS, Alt is Option. These configurable shortcuts run on pages where the extension's content script is active and access is allowed.

The browser's extension command system also defines defaults of Alt + Shift + 1 for batch collection, Alt + Shift + 2 for area capture, and Alt + Shift + 3 for visible capture. Manage those browser-level commands in the browser's extension shortcut settings. A browser or OS can intercept a combination before the extension sees it.

## Interface settings

Choose **Light**, **Dark** or **System**, and English or Polish. System follows the operating system's color scheme. Workspace and folder names come from Studio and retain their existing language.

## Advanced options

Open **Advanced options** under Websites and connections.

| Setting | Default | Accepted range or format |
| --- | --- | --- |
| Maximum file size | 64 MiB | 1–128 MiB |
| Concurrent uploads | 2 | 1–3 |
| Excluded pages | Empty | Full page URLs, separated by whitespace, up to 200 |
| Excluded hosts | Empty | Hostnames separated by whitespace or commas, up to 200 |

The extension applies its download cap together with the applicable server upload limit. Changing it cannot raise Studio's server-side ceiling. For a page exclusion, use the current URL. For a domain exclusion, use its hostname.

The **Custom collection attributes** section lists the HTML attributes recognized by the collector. Site owners can use those to supply stable names, tags and higher-resolution sources. [Website integration](/browser-extension/website-integration)
