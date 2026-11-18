import type { TransferTransport } from './types';
export declare class TransferHttpError extends Error {
    readonly status: number;
    constructor(status: number, message: string);
}
export declare function createUploadTransport(options: {
    baseUrl: string;
    csrfToken?: () => string;
    credentials?: RequestCredentials;
}): TransferTransport;
