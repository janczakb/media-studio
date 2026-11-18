import type { ManagementTransport } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function SharesPage({ workspace, transport, portal, guestUploads, locale }: {
    workspace: string;
    transport: ManagementTransport;
    portal: HTMLElement;
    guestUploads?: boolean;
    locale?: ManagementLocale;
}): import("react/jsx-runtime").JSX.Element;
