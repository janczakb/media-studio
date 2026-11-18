import type { Entry } from '../contracts';
import type { TransferItem, TransferQueue, TransferTransport } from './types';
export declare function createTransferQueue(options: {
    transport: TransferTransport;
    concurrency?: number;
    maxFiles?: number;
    maxRetainedCompleted?: number;
    progressIntervalMs?: number;
    chunkThresholdBytes?: number;
    retries?: number;
    onComplete?: (entry: Entry, item: TransferItem) => void;
}): TransferQueue;
