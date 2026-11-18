import type { BrowserPushControl } from './useBrowserPush';
import type { Messages } from '../../ui';
import type { ManagementTransport } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function notificationEventLabels(t: import('./shared').ManagementMessages): Record<string, string>;
export declare function relativeTime(value: string, locale: string, now: number): string;
export declare function NotificationsPage({ browserPush, t: appMessages, workspace, transport, locale, onOpen, onUnread, canMarkRead }: {
    browserPush: BrowserPushControl;
    t: Messages;
    workspace: string;
    transport: ManagementTransport;
    locale?: ManagementLocale;
    onOpen?: (id: string) => void;
    onUnread?: (count: number) => void;
    canMarkRead?: boolean;
}): import("react/jsx-runtime").JSX.Element;
