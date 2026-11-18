/** Horizontal construction, rotated by the renderer for a vertical dock. One closed outline. */
export declare function dockSurface(length: number, depth: number, center: number, span: number): string;
export declare const dockSpring: (duration: number, bounce?: number) => {
    type: "spring";
    stiffness: number;
    damping: number;
    mass: number;
    restDelta: number;
    restSpeed: number;
};
