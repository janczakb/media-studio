import { type TreeOperation } from './tree-operations';
import type { StudioTransport } from './contracts';
export declare class StudioHttpError extends Error {
    readonly status: number;
    constructor(status: number, message: string);
}
export declare function createHttpTransport(options: {
    baseUrl: string;
    csrfToken?: () => string;
    credentials?: RequestCredentials;
    onTreeOperation?: (operation: TreeOperation) => void;
    navigationSubscribe?: (workspace: string, listener: () => void) => () => void;
}): StudioTransport;
