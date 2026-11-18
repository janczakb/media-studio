---
title: Preview SDK and controls
description: Customize image zoom, gestures, file actions, and the preview toolbar.
---


Configure each Studio instance through `StudioOptions`. No global viewer state is
used. Existing transport methods continue to authorize and persist file mutations.

```ts
import type {FilePreviewHandle, ImageViewportHandle, StudioOptions} from '@useflex/media-studio';

let preview: FilePreviewHandle | null = null;
let image: ImageViewportHandle | null = null;

const options: StudioOptions = {
  transport,
  previewTitleEditable: true,
  onPreviewReady(handle) { preview = handle; },
  imagePreview: {
    zoomControls: 'header', // 'hidden' for custom controls
    gestures: true,
    wheelBehavior: 'zoom', // default; use 'pan' for ordinary-wheel panning
    fitPadding: 0.1, // 90% of the full viewport fit
    maxRelativeZoom: 8, // relative to the unpadded fit; additionally texture-limited
    animationMs: 200,
    onReady(handle, entry) { image = handle; },
    onViewport(state, entry) { /* ready, zoom, fit, fitZoom, x, y, minZoom, maxZoom */ },
  },
};

preview?.setZoom(2);
preview?.showPanel('comments'); // information | comments | assistant | none
preview?.navigate('next');
preview?.search('invoice'); // false when the current format has no search
await preview?.print(); // false when printing is unavailable or forbidden
await preview?.rename('Updated photograph.jpg', new AbortController().signal);
preview?.close();

image?.zoomTo(1); // animated actual intrinsic image size
image?.zoomTo('fit');
image?.zoomBy(1.5, {point: {x: 400, y: 300}}); // viewport/client coordinates
image?.panBy(-80, 0);
const unsubscribe = image?.subscribe(state => { /* discrete commands / settled gestures */ });
unsubscribe?.();
```

`onPreviewReady(null)` and image `onReady(null, entry)` invalidate the handles on
teardown. A single preview handle follows next/previous navigation; the image
handle belongs to the decoded file/version. Do not retain and reuse an old image
handle. Printing obeys download permission; comments/assistant commands return
false when their feature is unavailable. Renaming uses the supplied exact filename,
revision and abort signal; the inline title editor preserves the existing extension.

The image plane uses compositor transforms. Button/double-click commands animate;
active pinch, trackpad wheel and pointer pan follow input directly, coalescing wheel
events into one update per animation frame. Header + and − use current-scale factors 1.25 and 0.75 and recenter with 200 ms cosine interpolation; the percentage button always centers actual size. Intrinsic metadata dimensions determine the percentage, with decoded dimensions as fallback when missing or inconsistent. The default minimum is the padded fit (or actual size for tiny images), not an arbitrary5%; the effective maximum also respects a16384-device-pixel texture budget. `minZoom` and `maxZoom` can set explicit absolute limits, subject to that texture budget.
Gesture notifications are settled rather
than triggering a React render for every animation frame. Touch pinch and WebKit
native gesture events share the same camera, with duplicate wheel suppression.
`prefers-reduced-motion` disables interpolation. Frame resize recalculates fit only
when geometry changes. No extra original download or raster copy is made by zoom.

The permanent library/preview action surface is controlled by `previewActions`;
`false` disables it. Library Add uses existing upload/create operations, Search
opens the library search modal, and the static saved agent appears only with an
enabled/configured assistant. Comments are the final ordinary preview action after
a separator, followed by the optional agent. `previewMenu` configures header menu
extensions independently.
