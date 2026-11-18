export interface ImageViewportState {
    ready: boolean;
    zoom: number;
    fit: boolean;
    fitZoom: number;
    x: number;
    y: number;
    minZoom: number;
    maxZoom: number;
}
export interface ImageViewportCommand {
    point?: {
        x: number;
        y: number;
    };
    animate?: boolean;
    center?: boolean;
}
export interface ImageViewportHandle {
    getState(): ImageViewportState;
    zoomTo(value: number | 'fit', options?: ImageViewportCommand): void;
    zoomBy(factor: number, options?: ImageViewportCommand): void;
    panBy(x: number, y: number): void;
    subscribe(listener: (state: ImageViewportState) => void): () => void;
    destroy(): void;
}
export interface ImageViewportOptions {
    minZoom?: number;
    maxZoom?: number;
    maxRelativeZoom?: number;
    fitPadding?: number;
    animationMs?: number;
    gestures?: boolean;
    wheelBehavior?: 'pan' | 'zoom';
}
/** One image camera. The layout box changes on resize, never on zoom frames. */
export declare function createImageViewport(plane: HTMLElement, frame: HTMLElement, options?: ImageViewportOptions, interaction?: (active: boolean) => void): ImageViewportHandle & {
    setImageSize(width: number, height: number): void;
    setEnabled(enabled: boolean): void;
};
