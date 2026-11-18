export interface ScrollAnchor {
    key: string;
    offset: number;
}
/** Capture only rendered rows; never scan the backing data or the document. */
export declare function captureScrollAnchor(viewport: HTMLElement | null, selector: string, attribute: string): ScrollAnchor | null;
export declare function restoreScrollAnchor(viewport: HTMLElement | null, keys: readonly string[], pitch: number, anchor: ScrollAnchor | null): void;
