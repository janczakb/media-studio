import type { BulkEntry, BulkEntryRequest, Entry, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
export declare function BulkDialog({ operation, items, workspace, transport, portal, t, onClose, onComplete, moveSubmit, onPending }: {
    operation: BulkEntryRequest['operation'];
    items: BulkEntry[];
    workspace: string;
    transport: StudioTransport;
    portal: HTMLDivElement;
    t: Messages;
    onClose: () => void;
    onComplete: (items: Entry[], parent: string | null) => void;
    onPending?: (ids: string[], pending: boolean) => void;
    moveSubmit?: (parent: string | null, signal: AbortSignal) => Promise<void>;
}): import("react/jsx-runtime").JSX.Element;
