export declare const PHOTO_ZOOM_MIN = 64;
export declare const PHOTO_ZOOM_MAX = 480;
export type PhotoZoomAnchor = {
    x: number;
    y: number;
};
export declare function clampPhotoZoom(value: number, min?: number, max?: number): number;
/** Normalize browser wheel units into a bounded logarithmic pinch increment. */
export declare function photoWheelDelta(delta: number, mode: number): number;
/** Select a column count without enlarging a target beyond its available width. */
export declare function photoGridGeometry(width: number, target: number, gap?: number): {
    columns: number;
    cell: number;
    row: number;
};
export declare function photoGeometryForColumns(width: number, columns: number, gap?: number): {
    columns: number;
    cell: number;
    row: number;
};
/** Adjacent density levels are compared by their actual fitted cell sizes. */
export declare function photoPinchLevel(width: number, columns: number, logScale: number, releasing?: boolean): {
    columns: number;
    scale: number;
};
