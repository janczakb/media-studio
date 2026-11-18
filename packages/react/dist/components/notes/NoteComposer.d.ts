import type { StudioTransport, Entry } from '../../contracts';
export declare function NoteComposer({ workspace, parent, entry, transport, portal, pl, editable, onClose, onSaved }: {
    workspace: string;
    parent: string | null;
    entry: Entry | null;
    transport: StudioTransport;
    portal: HTMLElement;
    pl: boolean;
    editable?: boolean;
    onClose: () => void;
    onSaved: (entry: Entry) => void;
}): import("react/jsx-runtime").JSX.Element;
