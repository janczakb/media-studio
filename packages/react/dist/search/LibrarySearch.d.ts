import type { BrowseExtensions, Entry, StudioTransport, Workspace } from '../contracts';
import { type Messages } from '../ui';
import type { LibrarySearchTransport } from './contracts';
export interface LibrarySearchProps {
    spotlight?: boolean;
    isOpen?: boolean;
    onOpenChange?: (value: boolean) => void;
    workspace: Workspace;
    workspaces: Workspace[];
    multiple: boolean;
    indexed: boolean;
    semantic?: boolean;
    transport: StudioTransport;
    search: LibrarySearchTransport;
    extensions: BrowseExtensions;
    portal: HTMLDivElement;
    t: Messages;
    onOpen: (workspace: string, entry: Entry) => void;
}
export declare function LibrarySearch({ workspace, workspaces, multiple, indexed, semantic, transport, search, extensions, portal, t, onOpen, spotlight, isOpen, onOpenChange }: LibrarySearchProps): import("react/jsx-runtime").JSX.Element;
