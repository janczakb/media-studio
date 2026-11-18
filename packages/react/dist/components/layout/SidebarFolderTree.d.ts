import type { FolderSummary, FolderTreeClient } from '../../folders/FolderTreeClient';
import { type Messages } from '../../ui';
export declare function SidebarFolderTree({ client, path, collapsed, active, selected, preferenceKey, t, onRoot, onFolder, refreshOnFocus }: {
    client: FolderTreeClient;
    refreshOnFocus?: boolean;
    path: readonly FolderSummary[];
    collapsed?: boolean;
    active: boolean;
    selected: string | null;
    preferenceKey: string;
    t: Messages;
    onRoot: () => void;
    onFolder: (folder: FolderSummary) => void;
}): import("react/jsx-runtime").JSX.Element;
