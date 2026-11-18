# FLEX Capture

Collect images and files from websites, save links or capture screenshots to a
workspace in your FLEX Media Studio installation.

## Ready-built downloads

- [Chrome / Edge ZIP](https://github.com/janczakb/media-studio/releases/download/v0.1.0/flex-capture-chrome.zip)
- [Firefox temporary ZIP](https://github.com/janczakb/media-studio/releases/download/v0.1.0/flex-capture-firefox.zip)
- [Checksums](https://github.com/janczakb/media-studio/releases/download/v0.1.0/SHA256SUMS.txt)

Extract the ZIP and follow INSTALL.md inside. These are unpacked/temporary distributions, not published browser-store listings.

## Local installation

Run `npm run build` from this directory after installing dependencies.

- **Chrome:** Open `chrome://extensions`, enable Developer mode and choose
  **Load unpacked**. Select `dist/chrome`. Reload the extension after a rebuild.
- **Firefox 140 or later:** Open `about:debugging#/runtime/this-firefox`, choose
  **Load Temporary Add-on** and select `dist/firefox/manifest.json`.

Open **Preferences → Websites and connections → Connect Studio**, provide your
installation URL and approve the workspace in Studio. The Studio browser
integration must be enabled on that installation.

Use the browser's context menu for files, links and screenshots. For Alt +
right-click, the configurable shortcuts and drag, grant access in Preferences
for the sites where you want these features. The three drag modes are Popup,
Bottom and Disabled. Disabling drag leaves the Alt + right-click setting
independent. On macOS, Alt is labelled Option.

## Verification

`npm run typecheck` checks the TypeScript sources. After a build, `npm test`
uses the Playwright Chromium installation to exercise the actual extension
popup, stored preferences, keyboard shortcut conflicts and right-click capture.
