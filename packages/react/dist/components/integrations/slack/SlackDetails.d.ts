import type { Workspace } from '../../../contracts';
import type { IntegrationOverview } from '../../../integrations';
import type en from '../locales/en';
export declare function SlackDetails({ info, error, workspace, t, locale, onDetail, onEdit, onDisconnect, workspaces, onWorkspace, portal, connecting, onConnect }: {
    info: IntegrationOverview | null;
    error: string;
    workspace: string | undefined;
    t: Record<keyof typeof en, string>;
    locale: string;
    onDetail: (value: null) => void;
    onEdit: () => void;
    onDisconnect: () => void;
    workspaces: Workspace[];
    onWorkspace: (id: string) => void;
    portal: HTMLDivElement;
    connecting: boolean;
    onConnect: () => void;
}): import("react/jsx-runtime").JSX.Element;
