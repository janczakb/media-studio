import type { Entry } from '../../contracts';
import { type Messages } from '../../ui';
export type EntryActionHandlers = {
    downloadFolders?: boolean;
    inspect?: (entry: Entry) => void;
    share?: (entry: Entry) => void;
    move?: (entry: Entry) => void;
    trash?: (entry: Entry) => void;
    download?: (entry: Entry) => void;
    copy?: (entry: Entry) => void;
    optimize?: (entry: Entry) => void;
};
export declare function EntryActions({ entry, portal, t, handlers, open, toggle, pending, className }: {
    entry: Entry;
    portal: HTMLDivElement;
    t: Messages;
    handlers: EntryActionHandlers;
    open: () => void;
    toggle: (() => void) | undefined;
    pending: boolean;
    className: string;
}): import("react/jsx-runtime").JSX.Element;
export declare function entryActionItems(entry: Entry, t: Messages, handlers: EntryActionHandlers, open: (() => void) | undefined, toggle: (() => void) | undefined, pending: boolean): {
    id: string;
    text: string;
    icon: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    run: ((entry: Entry) => void) | undefined;
}[];
