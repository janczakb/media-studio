import { type RefObject } from 'react';
import type { BulkEntry, Entry } from '../../contracts';
type Options = {
    viewport: RefObject<HTMLDivElement | null>;
    dropBar: RefObject<HTMLDivElement | null>;
    portal: HTMLDivElement;
    enabled: boolean;
    identity: string;
    workspace: string;
    items: readonly Entry[];
    selection: ReadonlyMap<string, BulkEntry>;
    onChoose: (items: BulkEntry[]) => void;
    onMove: (items: BulkEntry[], parent: string) => void;
};
/** One gesture owner for mounted cards; movement and edge scrolling stay outside React. */
export declare function useMoveDrag(options: Options): number;
export {};
