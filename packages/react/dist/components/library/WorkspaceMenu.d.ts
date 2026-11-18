import type { StudioTransport, Workspace } from '../../contracts';
export declare function WorkspaceMenu({ workspace, transport, portal, onSaved, pl }: {
    workspace: Workspace;
    transport: StudioTransport;
    portal: HTMLElement;
    onSaved: (workspace: Workspace) => void;
    pl: boolean;
}): import("react/jsx-runtime").JSX.Element | null;
