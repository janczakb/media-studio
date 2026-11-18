import type { AssistantAction } from './contracts';
import type { AssistantConfiguration, AssistantTransport } from './contracts';
interface Props {
    open: boolean;
    onOpen: () => void;
    historyOpen: boolean;
    onHistoryChange: (value: boolean) => void;
    onClose: () => void;
    host: HTMLDivElement;
    target: HTMLDivElement;
    preview: boolean;
    workspace: string;
    file: string | null;
    folder: string | null;
    transport: AssistantTransport;
    configuration: AssistantConfiguration;
    locale: string;
    identity: string;
    onMutation: () => void;
    onAction: (action: AssistantAction) => void;
    userName: string;
}
export default function AssistantSurface({ open, onOpen, historyOpen, onHistoryChange: setHistoryOpen, onClose, host, target, preview, workspace, file, folder, transport, configuration, locale, identity, onMutation, onAction, userName }: Props): import("react/jsx-runtime").JSX.Element;
export {};
