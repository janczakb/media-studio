import type { ApiAccessTransport } from '../../api-access';
import type en from './locales/en';
type Messages = Record<keyof typeof en, string>;
export declare function ApiAccessPanel({ workspace, transport, t, portal, locale, copy }: {
    workspace: string;
    transport: ApiAccessTransport;
    t: Messages;
    portal: HTMLDivElement;
    locale: string;
    copy: (value: string) => Promise<void>;
}): import("react/jsx-runtime").JSX.Element;
export {};
