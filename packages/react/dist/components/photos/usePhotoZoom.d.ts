import { type RefObject } from 'react';
import { type PhotoZoomAnchor } from './photo-zoom';
export type PhotoZoomOptions = {
    viewportRef: RefObject<HTMLElement | null>;
    onBegin: (anchor: PhotoZoomAnchor) => void;
    onPinch: (logDelta: number, anchor: PhotoZoomAnchor) => void;
    onEnd: () => void;
    onSelect: (value: number) => void;
    enabled?: boolean;
};
/** A single gesture stream, with frame-batched logarithmic input. */
export declare function usePhotoZoom({ viewportRef, onBegin, onPinch, onEnd, onSelect, enabled }: PhotoZoomOptions): {
    setZoom: (value: number) => void;
};
