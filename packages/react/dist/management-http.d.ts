import type { ManagementTransport } from './management-contracts';
export declare function createManagementTransport(options: {
    baseUrl: string;
    csrfToken?: () => string;
    credentials?: RequestCredentials;
}): ManagementTransport;
