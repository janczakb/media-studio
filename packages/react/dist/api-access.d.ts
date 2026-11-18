export interface ApiTokenRecord {
    id: string;
    name: string;
    suffix: string;
    scope: 'read' | 'write';
    status: 'active' | 'expired' | 'revoked';
    expiresAt: string;
    createdAt: string;
    lastUsedAt: string | null;
}
export interface ApiUsagePoint {
    date: string;
    requests: number;
    successes: number;
    errors: number;
    durationMs: number;
}
export interface ApiAccessOverview {
    enabled: boolean;
    canManage: boolean;
    endpoint: string;
    workspace: string;
    requestsPerMinute: number;
    usageCacheSeconds: number;
    maxDays: number;
    items: ApiTokenRecord[];
    usage: ApiUsagePoint[];
}
export interface ApiAccessTransport {
    overview(workspace: string, signal: AbortSignal): Promise<ApiAccessOverview>;
    create(workspace: string, input: {
        name: string;
        scope: 'read' | 'write';
        days: number;
    }, signal: AbortSignal): Promise<{
        token: string;
        item: ApiTokenRecord;
    }>;
    revoke(workspace: string, token: string, signal: AbortSignal): Promise<{
        revoked: boolean;
    }>;
}
