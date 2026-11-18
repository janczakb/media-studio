import type { StudioTransport, Workspace } from '../../contracts';
export declare function IntegrationsPage({ transport, workspace, workspaces, locale, portal, search, detail, onDetail, onAi, onWorkspace }: {
    transport: StudioTransport;
    workspace: string | undefined;
    workspaces: Workspace[];
    locale: string;
    portal: HTMLDivElement;
    search: string;
    detail: 'slack' | null;
    onDetail: (value: 'slack' | null) => void;
    onAi: (tab: 'api' | 'connection') => void;
    onWorkspace: (id: string) => void;
}): import("react/jsx-runtime").JSX.Element;
