import type { IntegrationOverview, IntegrationsTransport } from '../../../integrations';
import type en from '../locales/en';
type Messages = Record<keyof typeof en, string>;
export declare function SlackDialog({ workspace, info, transport, portal, t, onSaved, onClose, onReconnect }: {
    workspace: string;
    info: IntegrationOverview;
    transport: IntegrationsTransport;
    portal: HTMLDivElement;
    t: Messages;
    onSaved: (info: IntegrationOverview) => void;
    onClose: () => void;
    onReconnect: () => void;
}): import("react/jsx-runtime").JSX.Element;
export {};
