import type { DrawingMark, DrawingPoint } from '../../contracts';
export declare function drawingBounds(mark: DrawingMark): {
    x: number;
    y: number;
    width: number;
    height: number;
};
/** Translate the whole object; clamping individual vertices would deform it. */
export declare function moveDrawing(mark: DrawingMark, dx: number, dy: number): DrawingMark;
export declare function resizeDrawing(mark: DrawingMark, corner: number, point: DrawingPoint): DrawingMark;
