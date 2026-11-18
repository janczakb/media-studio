import type { FolderNavigationTransport, NavigationChangePage } from './contracts';
/** Ordered, bounded replay. Push is a hint; every delta is authenticated by the transport. */
export declare class NavigationSync {
    readonly workspace: string;
    private transport;
    private changed;
    private revision;
    private controller;
    private pending;
    private dirty;
    private unsubscribe;
    private stopped;
    constructor(workspace: string, transport: FolderNavigationTransport, changed: (page: NavigationChangePage) => void);
    start(): void;
    refresh(): Promise<void>;
    destroy(): void;
}
