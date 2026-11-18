import type { StudioTransport, Workspace } from '../../contracts';
type Props = {
    workspace: Workspace;
    transport: StudioTransport;
    portal: HTMLElement;
    locale: string;
};
export declare function WorkspaceMembers({ workspace, transport, portal }: Props): import("react/jsx-runtime").JSX.Element | null;
export {};
