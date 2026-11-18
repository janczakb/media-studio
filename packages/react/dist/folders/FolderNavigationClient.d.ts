import type { AncestorPage, FolderContext, FolderNavigationTransport } from './contracts';
export interface NavigationSnapshot {
    context: FolderContext | null;
    pages: readonly AncestorPage[];
    loading: boolean;
    error: Error | null;
    /** Earlier loaded ancestors were evicted. resetAncestors restores the nearest page. */
    windowed: boolean;
}
/** Framework-independent ancestor navigation; one admitted read and a bounded page window. */
export declare class FolderNavigationClient {
    readonly workspace: string;
    private transport;
    private controller;
    private listeners;
    private generation;
    private state;
    private pending;
    private destroyed;
    constructor(workspace: string, transport: FolderNavigationTransport);
    getSnapshot: () => NavigationSnapshot;
    subscribe: (listener: () => void) => () => void;
    private publish;
    open(folder: string): Promise<FolderContext>;
    loadMore(): Promise<void>;
    resetAncestors(): void;
    cancel(): void;
    destroy(): void;
}
