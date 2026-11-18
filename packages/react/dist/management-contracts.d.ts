import type { BrowserPushTransport } from './browser-push';
import type { Entry } from './contracts';
export interface CursorPage<T> {
    items: T[];
    nextCursor: string | null;
    previousCursor?: string | null;
    hasMore: boolean;
}
export type ShareVisibility = 'share' | 'publish';
export type SharePermission = 'view' | 'comment' | 'edit';
export interface ShareAnalytics {
    visits: number;
    measuredVisits: number;
    averageSeconds: number | null;
    lastVisitedAt: string | null;
    points: {
        date: string;
        visits: number;
        averageSeconds?: number | null;
    }[];
    countries: {
        country: string;
        visits: number;
    }[];
}
export interface ShareRecord {
    visits30d?: number;
    lastVisitedAt?: string | null;
    visibility?: ShareVisibility;
    collectAnalytics?: boolean;
    allowIndexing?: boolean;
    allowCopy?: boolean;
    hasRecoverableUrl?: boolean;
    recipientEmail?: string | null;
    recipientPermission?: SharePermission | null;
    id: string;
    title: string;
    kind: 'file' | 'folder' | 'selection';
    allowDownload: boolean;
    allowArchive?: boolean;
    allowUpload?: boolean;
    passwordProtected: boolean;
    expiresAt: string | null;
    revokedAt: string | null;
    createdAt: string;
    revision: number;
    entryCount: number;
    ownedByMe: boolean;
    canEdit?: boolean;
}
export interface ShareQuery {
    summary?: boolean;
    entry?: string;
    visibility?: ShareVisibility;
    audience?: 'link' | 'invite';
    status: 'active' | 'expired' | 'revoked' | 'all';
    kind: 'all' | 'file' | 'folder' | 'selection';
    mine: boolean;
}
export interface ShareCreate {
    recipientPermission?: SharePermission;
    visibility?: ShareVisibility;
    collectAnalytics?: boolean;
    allowIndexing?: boolean;
    allowCopy?: boolean;
    title: string;
    entryIds: string[];
    allowDownload: boolean;
    allowArchive?: boolean;
    allowUpload?: boolean;
    password?: string | null;
    expiresAt?: string | null;
}
export interface ShareUpdate {
    unpublish?: boolean;
    collectAnalytics?: boolean;
    allowIndexing?: boolean;
    allowCopy?: boolean;
    recipientPermission?: SharePermission;
    revision: number;
    title?: string;
    allowDownload?: boolean;
    allowArchive?: boolean;
    allowUpload?: boolean;
    revoked?: boolean;
    password?: string | null;
    expiresAt?: string | null;
}
export type ShareCreated = ShareRecord & {
    url: string;
};
export type SharePage = CursorPage<ShareRecord> & {
    canManage: boolean;
};
export interface TrashRecord {
    activityId?: string;
    id: string;
    name: string;
    kind: 'file' | 'folder';
    mediaType: string | null;
    size: number | null;
    revision: number;
    trashedAt: string | null;
    actor: {
        type: string;
        id: string;
    } | null;
    occurredAt: string;
    available?: boolean;
    canRestore?: boolean;
    purging?: boolean;
}
export interface TrashQuery {
    tab: 'all' | 'files' | 'folders' | 'restorations';
    actor: 'all' | 'me';
    after?: string;
}
export type TrashPage = CursorPage<TrashRecord> & {
    retention: {
        enabled: boolean;
        days: number;
    };
};
export interface StudioNotification {
    actor?: {
        name: string | null;
        avatarUrl: string | null;
    } | null;
    id: string;
    event: string;
    name: string;
    entryId: string | null;
    createdAt: string;
    readAt: string | null;
}
export interface StudioTag {
    id: string;
    label: string;
    locale?: string;
    type?: string;
    count?: number;
}
export interface ManagementTransport {
    browserPush?: BrowserPushTransport;
    inviteShare?(workspace: string, input: {
        title: string;
        entryIds: string[];
        emails: string[];
        permission: SharePermission;
    }, signal: AbortSignal): Promise<{
        items: ShareRecord[];
        failed: string[];
    }>;
    shareLink?(workspace: string, id: string, signal: AbortSignal): Promise<{
        url: string;
    }>;
    shareAnalytics?(workspace: string, id: string, days: 7 | 30 | 90, signal: AbortSignal): Promise<ShareAnalytics>;
    deleteShare?(workspace: string, id: string, revision: number, signal: AbortSignal): Promise<{
        deleted: boolean;
    }>;
    shares?(workspace: string, query: ShareQuery, cursor: string | null, signal: AbortSignal): Promise<SharePage>;
    createShare?(workspace: string, input: ShareCreate, signal: AbortSignal): Promise<ShareCreated>;
    updateShare?(workspace: string, id: string, input: ShareUpdate, signal: AbortSignal): Promise<ShareRecord>;
    rotateShare?(workspace: string, id: string, revision: number, signal: AbortSignal): Promise<ShareCreated>;
    trashReport?(workspace: string, query: TrashQuery, cursor: string | null, signal: AbortSignal): Promise<TrashPage>;
    restoreEntry?(workspace: string, id: string, revision: number, signal: AbortSignal): Promise<Entry>;
    readVisibleNotifications?(workspace: string, ids: string[], signal: AbortSignal): Promise<{
        updated: number;
        unreadCount: number;
    }>;
    notifications?(workspace: string, unread: boolean, cursor: string | null, signal: AbortSignal): Promise<CursorPage<StudioNotification> & {
        unreadCount?: number;
    }>;
    readNotification?(workspace: string, id: string, read: boolean, signal: AbortSignal): Promise<Pick<StudioNotification, 'id' | 'readAt'>>;
    readAllNotifications?(workspace: string, signal: AbortSignal): Promise<{
        updated: number;
    }>;
    tags?(workspace: string, search: string, cursor: string | null, signal: AbortSignal): Promise<CursorPage<StudioTag>>;
}
