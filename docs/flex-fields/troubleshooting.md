---
title: "FLEX assets and troubleshooting"
description: "Check lazy loading, file previews, scope restrictions and image publication."
---

Serve the Studio distribution that matches the Laravel integration. FLEX's own asset manager loads the field UI, and the Studio picker loads its React module and stylesheet when mounted. Use the browser's network and console tools to inspect a failing asset request.

| Symptom | Check |
| --- | --- |
| `mediaStudio()` is unavailable | Confirm the installed FLEX package has the Studio factory and that Studio's Laravel package is available. |
| Picker opens without styling | Verify the configured stylesheet URL, response type and host content security policy. |
| Rich editor cannot insert responsive images | Serve `rich-editor-extension.js` beside the configured Studio module and use the plugin during rendering. |
| Uploads are blocked | Check field `studioUpload()`, workspace upload permission, MIME restrictions and ordinary size limits. |
| Existing selection is missing | Check pinned version availability and workspace/tenant access. [Reference lifecycle](/flex-fields/persistence) |
| Images show a file fallback | Confirm a ready rendition matches `studioThumbnailPreset()`. [Conversion configuration](/image-rendition-presets) |
| PDF is absent | Check the intersection of `acceptedMimeTypes` and `acceptedFileTypes`. Keep folder navigation available. |
| Picker allows too few files | Existing selections count toward `maxFiles`; deselect a selected file to free a slot. |
| AI action is absent | Check image-editing model configuration, field enabled state and `studioAiImageEditor()`. |
| Accepted edit loses another selection | Verify the publisher replaces only the selected file key and returns the new Studio reference. |
| Superseded version remains in storage | Check references, retirement records, the PHP cleanup policy and scheduled maintenance. |

## Check lazy loading with a real form

Reload the form, inspect the selected-file tiles, then open and close Choose media twice. Verify one active picker instance and successful asset responses. Confirm the user's selected values survive closing the modal and that field-specific folder/MIME constraints still apply on the next opening.

Keep tokens, provider credentials and internal storage addresses in server-side configuration. See [Filament setup](/filament-setup) for shared installation steps and [operating Studio](/operations) for workers and delivery.
