import type { StudioTransport, Workspace, WorkspaceMember } from '../../contracts';
export declare function MembersTable({ workspace, transport, portal, pl, revision, busy, onRole, onRemove }: {
    workspace: Workspace;
    transport: StudioTransport;
    portal: HTMLElement;
    pl: boolean;
    revision: number;
    busy: boolean;
    onRole: (person: WorkspaceMember, role: string) => void;
    onRemove: (person: WorkspaceMember) => void;
}): import("react/jsx-runtime").JSX.Element;
