import { type RefObject } from 'react';
import type { BulkEntry } from '../../contracts';
/** Only mounted cards are measured. Visited geometry is bounded by the bulk limit. */
export declare function useMarquee({ viewport, surface, enabled, selection, onChange, limit }: {
    viewport: RefObject<HTMLDivElement | null>;
    surface?: RefObject<HTMLElement | null>;
    enabled: boolean;
    selection: ReadonlyMap<string, BulkEntry>;
    onChange: (value: ReadonlyMap<string, BulkEntry>) => void;
    limit?: number;
}): void;
