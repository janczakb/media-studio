import type { StudioTransport, Workspace } from '../../contracts';
export declare function BrowserExtensionApproval({ transport, workspaces, workspace, portal }: {
    transport: StudioTransport;
    workspaces: Workspace[];
    workspace: string | undefined;
    locale: string;
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element | null;
