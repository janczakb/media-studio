import type { StudioTransport, Workspace } from '../../contracts';
export declare function AddPeopleDialog({ workspace, transport, portal, onClose, onChanged }: {
    workspace: Workspace;
    transport: StudioTransport;
    portal: HTMLElement;
    onClose: () => void;
    onChanged: () => void;
}): import("react/jsx-runtime").JSX.Element;
