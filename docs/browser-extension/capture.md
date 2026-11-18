---
title: "Save and organize from the browser"
description: "Use files, batches, URLs, selected text, screenshots and drag workflows."
---

The extension offers several ways to start a save. Review the destination when prompted, then check Transfers for the final result. Browser-menu labels use the extension name **FLEX Capture**.

## Save one file

Right-click an image, video, audio element or supported download link and choose **Save file to FLEX Capture**. If the browser groups extension actions under its own submenu, open that submenu first.

With **Ask where to save** enabled, select the file, destination and tags in the opened save surface. With it disabled, an eligible quick save can use the first valid connection and its workspace root when source-site access is already granted. A missing connection or source grant opens the confirmation workflow.

**Alt + right-click capture** starts the quick workflow directly on a supported image or file. On macOS the modifier is Option. Enable this independently from the Drag feature. [Preferences and website permissions](/browser-extension/preferences)

## Save a page or selected text

Choose **Save URL** in the popup to create a note containing the current page's link and title. If you selected text first, that text is included in the note, up to 20,000 characters.

The browser context menu also offers **Save URL** on a page or link and **Save selected text** on a selection. A clicked link is saved using its URL. These actions create the note immediately in the connected workspace root. They require note-creation permission and use the first saved connection. Check that connection before using the shortcut in an installation with multiple approvals.

## Collect a set of assets

1. Open a source page and choose **Batch save**.
2. Search the detected candidates by name or URL. Filter by format, source domain, minimum width or minimum height.
3. Select individual items, choose **Select all** for the currently filtered candidates, or invert the filtered selection.
4. Choose the destination and tags, then press **Save**.
5. The manager opens **Transfers** to show each file's result.

A save can contain up to 300 selected assets. The collector retains up to 3,000 candidates and visits at most 20,000 page elements in a collection pass. Use narrower source pages when a collection is truncated. Files and images loaded from another host may need an additional browser permission before saving.

For example, collect a supplier's product images, filter to the supplier's image host and at least 800 pixels in width, then save the selected set under `Research / Product references` with a `supplier` tag.

### Detect a scrolling board

On recognized board-style sites, the popup can offer **Save board’s images**. That action collects the initial candidates, scrolls to discover more and opens the batch manager when detection finishes. The current pass is bounded to two minutes, 3,000 candidates and five consecutive scans without new assets. Use the page's **Stop · FLEX Capture** button to finish detection early.

Detection depends on what the website renders and makes downloadable in that session. Review the discovered candidates before choosing Save.

## Choose the destination

The destination panel displays a workspace chooser when the installation enables workspaces. It offers approved connections for that Studio installation. If workspace switching is globally disabled, the chooser is hidden and the approved library remains the destination.

| Control | Behavior |
| --- | --- |
| Workspace | Change the approved workspace connection |
| Search folders | Search names by prefix among children of the currently opened folder |
| Folder name | Select that folder as the destination |
| Arrow beside a folder | Open its children |
| Back arrow | Return to the parent folder |
| All files | Select the workspace root |
| Selection check | Identify the currently selected destination |

Browsing uses pages of up to 60 folders with cursor-based loading in both directions. TanStack Virtual renders the visible rows and the picker retains a bounded data window. Opening a folder requests its own children.

The current batch and drag save surfaces browse existing folders. Create a new destination in Studio first, then search for or open it in the extension. The [extension API](/browser-extension/website-integration#extension-api) also exposes an authorized folder-creation endpoint for clients that implement that workflow.

## Apply tags

Type into **Add tags** to search existing workspace labels. Choose a suggestion or use **Create** for a new label, then add it. Remove a selected tag with its remove control before saving. Up to 32 tags can be supplied and the server checks tag-management permission when applying metadata.

Tags and source information are applied after the file is accepted. A metadata failure can leave a file present in Studio while Transfers still reports an error. Retry the job to finish its metadata step. [Transfer completion](/browser-extension/transfers#understand-completion)

## Drag into Studio

Enable the Drag feature for the current website in the popup, then choose **Popup** or **Bottom** in Preferences.

- **Popup** opens the save destination next to the drag workflow.
- **Bottom** provides a drop target near the page's bottom edge.
- **Disabled** switches off drag handling.

After dropping one or more supported assets, the save surface displays the selection and destination options. Choose the folder and tags before confirming when the destination prompt is enabled. Multiple assets are represented as a stack of up to three previews. A batch can contain up to 300 assets. Missing website access sends the selection to the manager for permission confirmation.

In drag flows, the upper-right status notice reports saving, success or partial failure. A queued job still needs to finish uploading and saving metadata.

## Save screenshots

| Action | Result |
| --- | --- |
| Capture area | Select a rectangle in the active page |
| Capture visible | Capture the current viewport |
| Capture page | Scroll and stitch the page into an image |

For an area, drag the rectangle and follow the save workflow. Escape dismisses the selection. The result opens in the manager where you choose a destination and save it. Keep the original tab active during capture. Changing the active tab stops capture.

Full-page capture temporarily scrolls the page and restores its previous position afterward. It is limited to 48 viewport tiles, a 32-megapixel output and dimensions of at most 32,767 pixels per edge. Very tall pages or high-density Retina output can exceed those bounds. Choose an area or turn off Retina resolution for a smaller capture. [Screenshot preferences](/browser-extension/preferences#screenshot-settings)

## Supported sources

The collector recognizes images, supported file links, HTML video and audio sources, suitable CSS backgrounds, and small readable canvas images. Higher-resolution discovery checks sources exposed by the page and its supported site metadata.

Source download still depends on authorization, host permission, a valid file response and Studio's MIME policy. Streaming playlists such as HLS or DASH and inaccessible blob sources require a different source workflow. A document link can be saved as a file when the source exposes a downloadable response. Use Save URL for a web page you want to keep as a reference.
