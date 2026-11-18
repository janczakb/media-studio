import { type RefObject } from 'react';
/** Layout-local coordinates remain stable while an enclosing modal scales or recenters. */
export declare function SlidingSelection({ host, selector, selection, className }: {
    host: RefObject<HTMLElement | null>;
    selector: string;
    selection: string;
    className: string;
}): import("react/jsx-runtime").JSX.Element;
