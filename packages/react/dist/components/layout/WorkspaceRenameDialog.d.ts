import type { Workspace, StudioTransport } from '../../contracts';
export declare function WorkspaceRenameDialog({ workspace, workspaces, transport, portal, onSaved, onClose, onDeleted, multiple }: {
    workspace: Workspace;
    workspaces: Workspace[];
    transport: StudioTransport;
    portal: HTMLElement;
    onSaved: (value: Workspace) => void;
    onClose: () => void;
    onDeleted: (target?: string | null) => void;
    multiple: boolean;
}): import("react/jsx-runtime").JSX.Element;
