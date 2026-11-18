import type { Entry, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
type DialogContext = {
    workspace: string;
    transport: StudioTransport;
    portal: HTMLDivElement;
    t: Messages;
    onClose: () => void;
};
export declare function MoveDialog({ entry, onChanged, submit, ...context }: DialogContext & {
    entry: Entry | null;
    onChanged: (entry: Entry) => void;
    submit?: (entry: Entry, parent: string | null, signal: AbortSignal) => Promise<void>;
}): import("react/jsx-runtime").JSX.Element | null;
export declare function DestinationDialog({ workspace, transport, portal, t, onClose, excludedIds, submit }: DialogContext & {
    excludedIds: readonly string[];
    submit: (parent: string | null, signal: AbortSignal) => Promise<void>;
}): import("react/jsx-runtime").JSX.Element;
export {};
