import type { StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
/** A nested overlay keeps the destination tree and its selection mounted underneath. */
export declare function CreateDestinationDialog({ workspace, transport, portal, t, parent, path, onClose, onCreated }: {
    workspace: string;
    transport: StudioTransport;
    portal: HTMLDivElement;
    t: Messages;
    parent: string | null;
    path: readonly string[];
    onClose: () => void;
    onCreated: () => void | Promise<void>;
}): import("react/jsx-runtime").JSX.Element;
