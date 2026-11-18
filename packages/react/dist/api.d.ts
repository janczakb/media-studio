import { type TreeOperationResult } from './tree-operations';
import type { FolderContext, AncestorPage, NavigationChangePage } from './folders/contracts';
import type { Entry, EntryDetails, EntryPage } from './contracts';
export declare class StudioApiError extends Error {
    readonly status: number;
    readonly retryAfter: number | null;
    constructor(status: number, message: string, retryAfter?: number | null);
}
export interface StudioApiOptions {
    baseUrl: string;
    workspace: string;
    /** Server-side secret. Never expose it in a public frontend bundle. */
    token: string | (() => string | Promise<string>);
    timeoutMs?: number;
    fetch?: typeof globalThis.fetch;
}
export interface StudioApiRequest {
    method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
    body?: BodyInit;
    headers?: HeadersInit;
    signal?: AbortSignal;
}
/** A dependency-free, workspace-bound REST client. Mutations are never automatically retried. */
export declare function createStudioApi(options: StudioApiOptions): {
    request: <T>(path: string, input?: StudioApiRequest) => Promise<T>;
    treeOperations: {
        status: (operation: string, signal?: AbortSignal) => Promise<TreeOperationResult>;
        advance: (operation: string, signal?: AbortSignal) => Promise<TreeOperationResult>;
        cancel: (operation: string, signal?: AbortSignal) => Promise<TreeOperationResult>;
    };
    files(input?: {
        cursor?: string | null;
        limit?: number;
        parent?: string | null;
        search?: string;
    }, signal?: AbortSignal): Promise<EntryPage>;
    file(entry: string, signal?: AbortSignal): Promise<EntryDetails>;
    folderContext(folder: string, signal?: AbortSignal): Promise<FolderContext>;
    folderAncestors(folder: string, cursor: string, limit?: number, signal?: AbortSignal): Promise<AncestorPage>;
    navigationChanges(after: number, signal?: AbortSignal): Promise<NavigationChangePage>;
    move(entry: string, parent: string | null, revision: number, requestId?: string, signal?: AbortSignal): Promise<Entry>;
    undoNavigation(operation: string, signal?: AbortSignal): Promise<{
        undone: boolean;
    }>;
    createFolder(name: string, parent?: string | null, signal?: AbortSignal): Promise<Entry>;
    rename(entry: string, name: string, revision: number, signal?: AbortSignal): Promise<Entry>;
    trash(entry: string, revision: number, signal?: AbortSignal): Promise<Entry>;
    restore(entry: string, revision: number, signal?: AbortSignal): Promise<Entry>;
    upload(file: Blob, name: string, key: string, parent?: string | null, signal?: AbortSignal): Promise<Entry>;
};
