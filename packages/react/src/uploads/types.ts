import type { Entry } from '../contracts';
export type TransferState = 'queued' | 'resolving' | 'uploading' | 'paused' | 'finalizing' | 'completed' | 'error' | 'cancelled';
export interface TransferItem {
    id: string;
    name: string;
    relativePath: string;
    workspaceId: string;
    parentId: string | null;
    size: number;
    loaded: number;
    state: TransferState;
    error: string | null;
    entry?: Entry;
}
export interface TransferSnapshot {
    ids: readonly string[];
    activeCount: number;
    pausedCount: number;
    pendingCount: number;
    isPaused: boolean;
    completedCount: number;
    failedCount: number;
    totalBytes: number;
    loadedBytes: number;
}
export interface UploadPolicy {
    enabled: boolean;
    chunkBytes: number;
    maxBytes: number;
    maxParts: number;
    maxConcurrent: number;
    ordinaryMaxBytes: number;
    multipartThresholdBytes?: number | null;
}
export interface UploadSession {
    id: string;
    state: string;
    transport?: 'local' | 's3-multipart';
    chunkBytes: number;
    size: number;
    acceptedParts: number[];
    entry: Entry | null;
}
export interface TransferTransport {
    policy(workspace: string, signal: AbortSignal): Promise<UploadPolicy>;
    resolveFolders(workspace: string, parent: string | null, paths: string[], signal: AbortSignal): Promise<{
        items: {
            path: string;
            id: string;
        }[];
    }>;
    ordinary(workspace: string, parent: string | null, file: File, key: string, signal: AbortSignal, progress: (loaded: number) => void): Promise<Entry>;
    createSession(workspace: string, parent: string | null, file: File, key: string, signal: AbortSignal): Promise<UploadSession>;
    session(workspace: string, id: string, signal: AbortSignal): Promise<UploadSession>;
    part(workspace: string, id: string, index: number, blob: Blob, signal: AbortSignal, progress: (loaded: number) => void, transport?: UploadSession['transport']): Promise<void>;
    complete(workspace: string, id: string, signal: AbortSignal): Promise<Entry>;
    cancel(workspace: string, id: string, signal: AbortSignal): Promise<void>;
}
export interface TransferQueue {
    invalidatePolicy(workspace: string): void;
    enqueue(files: Iterable<File>, context: {
        workspaceId: string;
        parentId: string | null;
    }): string[];
    subscribe(listener: () => void): () => void;
    getSnapshot(): TransferSnapshot;
    subscribeItem(id: string, listener: () => void): () => void;
    getItem(id: string): TransferItem | null;
    pauseAll(): void;
    resumeAll(): void;
    pause(id: string): void;
    resume(id: string): void;
    retry(id: string): void;
    cancel(id: string): void;
    clearFinished(): void;
    destroy(): void;
}
