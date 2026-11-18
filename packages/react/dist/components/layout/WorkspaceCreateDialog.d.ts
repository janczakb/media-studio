import type { StudioTransport, Workspace } from '../../contracts';
type Props = {
    directoryWorkspace?: string | undefined;
    transport: StudioTransport;
    portal: HTMLElement;
    identityEnabled: boolean;
    onCreated: (w: Workspace) => void;
    onClose: () => void;
};
export declare function WorkspaceCreateDialog({ transport, portal, identityEnabled, onCreated, onClose, directoryWorkspace }: Props): import("react/jsx-runtime").JSX.Element;
export {};
