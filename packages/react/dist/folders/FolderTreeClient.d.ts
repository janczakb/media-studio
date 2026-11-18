import type { Entry, StudioTransport } from '../contracts';
export type FolderSummary = Pick<Entry, 'id' | 'name' | 'hasChildFolders' | 'readOnly'>;
export type FolderTreeRow = {
    key: string;
    depth: number;
    parent: string | null;
    folder: FolderSummary | null;
    page: number;
    cursor: string | null;
    edge: 'next' | 'previous' | null;
    error: string;
};
export interface FolderTreeSnapshot {
    root: FolderSummary | null;
    canWrite: boolean;
    rows: readonly FolderTreeRow[];
    expanded: ReadonlySet<string | null>;
    loading: ReadonlySet<string>;
    version: number;
    restoring: boolean;
}
export interface FolderTreeOptions {
    pageSize?: number;
    /** Idle response pages. Expanded outlines remain resident within branch/window limits. */
    cachedPages?: number;
    concurrency?: number;
}
/** Per-instance, per-workspace folder cache. Cursor windows also bound metadata. */
export declare class FolderTreeClient {
    readonly workspace: string;
    private browse;
    private branches;
    private expanded;
    private requests;
    private listeners;
    private visible;
    private visibleFolders;
    private path;
    private root;
    private clock;
    private sequence;
    private snapshot;
    private suspended;
    private restoring;
    private pageSize;
    private cachedPages;
    private concurrency;
    constructor(workspace: string, browse: StudioTransport['browse'], options?: FolderTreeOptions);
    subscribe: (listener: () => void) => () => void;
    getSnapshot: () => FolderTreeSnapshot;
    private branch;
    private page;
    private requestKey;
    private publish;
    restore(ids: readonly (string | null)[]): void;
    reveal(path: readonly FolderSummary[], collapsed?: boolean): void;
    pathTo(folder: FolderSummary): FolderSummary[];
    parentOf(folder: FolderSummary): FolderSummary | null;
    toggle(id: string | null, folder?: FolderSummary, parent?: string | null): void;
    private isVisible;
    retry(row: FolderTreeRow): void;
    areRowsReady(rows: readonly FolderTreeRow[]): boolean;
    private restorationRows;
    loadVisible(rows: readonly FolderTreeRow[]): void;
    private load;
    private validate;
    invalidate(parents: readonly (string | null)[]): void;
    changed(entry: Entry, parent?: string | null): void;
    applyChanges(changes: readonly {
        payload: Record<string, unknown>;
    }[], reset?: boolean): void;
    refresh(): void;
    private trim;
    suspend(): void;
    getStats(): {
        branches: number;
        pages: number;
        cachedPages: number;
        folders: number;
        idleCachedPages: number;
        summaries: number;
        expanded: number;
        requests: number;
    };
    destroy(): void;
}
/** Observe successful writes at the transport boundary, including custom transports. */
export declare function createFolderTreeTransport(source: StudioTransport, options?: FolderTreeOptions): {
    tree: (workspace: string) => FolderTreeClient;
    transport: StudioTransport;
    destroy(): void;
};
