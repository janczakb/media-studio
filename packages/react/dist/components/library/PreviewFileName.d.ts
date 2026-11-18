import type { Entry } from '../../contracts';
import type { Messages } from '../../ui';
export declare function PreviewFileName({ entry, editable, t, onRename }: {
    entry: Entry;
    editable: boolean;
    t: Messages;
    onRename: (name: string, signal: AbortSignal) => Promise<void>;
}): import("react/jsx-runtime").JSX.Element;
