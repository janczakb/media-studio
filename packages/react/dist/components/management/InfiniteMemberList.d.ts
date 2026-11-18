import { type ReactNode } from 'react';
type Page<T> = {
    items: T[];
    nextCursor: string | null;
};
type Props<T> = {
    identity: string;
    load: (cursor: string | null, signal: AbortSignal) => Promise<Page<T>>;
    getKey: (item: T) => string;
    render: (item: T) => ReactNode;
    empty: string;
    retry: string;
};
/** Profile payloads are bounded to eight pages. Evicted pages are refetched on backward scrolling. */
export declare function InfiniteMemberList<T>(props: Props<T>): import("react/jsx-runtime").JSX.Element;
export {};
