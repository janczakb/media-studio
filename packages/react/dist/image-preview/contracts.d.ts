import type { Entry } from '../contracts';
import type { ImageViewportHandle, ImageViewportOptions, ImageViewportState } from './viewport';
export interface ImagePreviewSettings extends ImageViewportOptions {
    zoomControls?: 'header' | 'hidden';
    onReady?: (handle: ImageViewportHandle | null, entry: Entry) => void;
    onViewport?: (state: ImageViewportState, entry: Entry) => void;
}
