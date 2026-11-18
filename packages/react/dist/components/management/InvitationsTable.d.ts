import type { StudioTransport, Workspace } from '../../contracts';
export declare function InvitationsTable({ workspace, transport, busy, onRevoke }: {
    workspace: Workspace;
    transport: StudioTransport;
    busy: boolean;
    onRevoke: (id: string) => void;
}): import("react/jsx-runtime").JSX.Element;
