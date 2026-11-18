import type { StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
export declare function SourceImportsPanel({ workspace, transport, t, onImported }: {
    onImported: (workspace: string) => void;
    workspace: string;
    transport: StudioTransport;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
