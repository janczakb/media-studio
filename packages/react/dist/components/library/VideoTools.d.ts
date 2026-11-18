import type { Entry, StudioOptions } from '../../contracts';
import { type Messages } from '../../ui';
export declare function VideoTools({ entry, workspace, options, t, onChanged, onEditing, onTrimReady }: {
    entry: Entry;
    workspace: string;
    options: StudioOptions;
    t: Messages;
    onChanged: (entry: Entry) => void;
    onEditing: (value: boolean) => void;
    onTrimReady: (start: (() => void) | null, busy: boolean) => void;
}): import("react/jsx-runtime").JSX.Element;
