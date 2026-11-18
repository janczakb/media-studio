---
title: Preview and review files
description: Review images, PDF and office documents, audio and video, and configure the Studio viewer.
---

Open a file in the library. The preview toolbar exposes actions supported by the file and your permissions. Rename the file from the preview title. Use **File information** to update the caption and, for images, the alternative text. Use **Comments** to open the discussion. A preview, an original download and a grid thumbnail can have different delivery paths.

| Media | Typical workflow |
| --- | --- |
| Images | Zoom, pan, inspect, and use drawing or annotation tools on eligible files. |
| PDF | Navigate pages, zoom, search text and print where the document and permissions allow. Scans without a text layer may not be searchable. |
| Office and text | Open supported office or text formats. Paged controls depend on the document engine and format. |
| Audio and video | Play browser-supported media. The [audio waveform](/audio-previews) and thumbnail can arrive separately. Use [video trimming](/video-editing) for eligible managed videos. |
| Unsupported file | Use available file details and authorized download actions. |

Use [image annotations](/image-annotations) for marks saved on a specific image version.

## Configure the embedded viewer

```ts
import type { StudioOptions } from '@useflex/media-studio';

export function configureReview(transport: StudioOptions['transport']): StudioOptions {
  return {
    transport,
    imagePreview: { zoomControls: 'header', gestures: true },
    onPreviewReady(preview) {
      if (preview) console.debug('Preview ready', preview);
    },
  };
}
```

Use `pdfPreview: false` or `documentPreview: false` to disable the corresponding engine per embedded instance. The [preview SDK](/image-preview-sdk) also shows how to drive zoom, panels, search and printing from your own controls.

When text search finds nothing in a scanned PDF, test selecting text in the document. When playback fails, check the media codec and the authenticated delivery response in the browser. Catch up on [document thumbnail workers](/document-thumbnails) if the grid lacks thumbnails while the viewer itself works.
