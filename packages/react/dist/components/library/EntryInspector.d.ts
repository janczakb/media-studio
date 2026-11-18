import type { Entry, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
export declare function EntryInspector({ entry, workspace, transport, canWrite, onChanged, t, duration, comments, portal, tagPortal, activeTab, onTabChange, hidden }: {
    entry: Entry;
    workspace: string;
    transport: StudioTransport;
    canWrite: boolean;
    onChanged: (entry: Entry) => void;
    t: Messages;
    duration?: number | undefined;
    comments?: boolean;
    portal: HTMLDivElement;
    tagPortal?: HTMLDivElement;
    hidden?: boolean;
    activeTab?: string;
    onTabChange?: (tab: string) => void;
}): import("react/jsx-runtime").JSX.Element;
