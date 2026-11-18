import type { Entry } from './contracts';
export interface TreeOperation {
    id: string;
    state: 'preparing' | 'completed' | 'failed' | 'cancelled';
    operation: 'trash' | 'restore';
    discovered: number;
    examined: number;
    error: string | null;
}
export interface TreeOperationResult {
    treeOperation: TreeOperation;
    items: Entry[];
}
export interface TreeOperationTransport {
    status(workspace: string, id: string, signal: AbortSignal): Promise<TreeOperationResult>;
    advance(workspace: string, id: string, signal: AbortSignal): Promise<TreeOperationResult>;
    cancel(workspace: string, id: string, signal: AbortSignal): Promise<TreeOperationResult>;
}
/** Aborting this wait leaves the durable operation resumable; cancellation is explicit. */
export declare function finishTreeOperation<T>(value: T | TreeOperationResult, advance: (id: string) => Promise<TreeOperationResult>, signal: AbortSignal, notify?: (operation: TreeOperation) => void): Promise<T | TreeOperationResult>;
