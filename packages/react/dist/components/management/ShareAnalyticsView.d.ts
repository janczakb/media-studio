import type { ManagementTransport, ShareRecord } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function ShareAnalyticsView({ workspace, record, transport, locale, portal }: {
    workspace: string;
    record: ShareRecord;
    transport: ManagementTransport;
    locale: ManagementLocale;
    portal: HTMLElement | null;
}): import("react/jsx-runtime").JSX.Element;
