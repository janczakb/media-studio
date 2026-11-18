import type { Entry } from '../../contracts';
import type { ManagementTransport } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function TrashPage({ workspace, transport, portal, locale, onOpen, onRestored }: {
    workspace: string;
    transport: ManagementTransport;
    portal: HTMLElement;
    locale?: ManagementLocale;
    onOpen?: (id: string) => void;
    onRestored?: (entry: Entry) => void;
}): import("react/jsx-runtime").JSX.Element;
