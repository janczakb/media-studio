import type { Entry } from '../../contracts';
import type { ManagementTransport, ShareCreated, ShareRecord, ShareVisibility, SharePermission } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export type ShareDialogProps = {
    workspace: string;
    entries?: readonly Pick<Entry, 'id' | 'name' | 'kind' | 'readOnly'>[];
    record?: ShareRecord;
    guestUploads?: boolean;
    transport: ManagementTransport;
    portal: HTMLElement;
    locale?: ManagementLocale;
    onClose: () => void;
    onCreated?: (value: ShareCreated) => void;
    onUpdated?: (value: ShareRecord) => void;
    visibility?: ShareVisibility;
    linkPermission?: SharePermission;
    actor?: {
        name: string;
        owner: boolean;
    };
};
export declare function ShareLinkEditor({ workspace, entries, record, guestUploads, transport, portal, locale, onClose, onCreated, onUpdated, visibility, linkPermission }: ShareDialogProps): import("react/jsx-runtime").JSX.Element;
