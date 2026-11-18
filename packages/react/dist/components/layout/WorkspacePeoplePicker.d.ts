import type { StudioTransport, WorkspaceMember } from '../../contracts';
export type WorkspaceCandidate = WorkspaceMember & {
    type: string;
};
export declare function WorkspacePeoplePicker({ workspace, transport, value, onChange, portal, disabled, candidateSource }: {
    candidateSource?: 'creation' | 'members';
    workspace: string;
    transport: StudioTransport;
    value: WorkspaceCandidate[];
    onChange: (items: WorkspaceCandidate[]) => void;
    portal: HTMLElement;
    disabled: boolean;
}): import("react/jsx-runtime").JSX.Element;
