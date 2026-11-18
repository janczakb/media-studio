import type { FolderNavigationClient } from './FolderNavigationClient';
import type { NavigationFolder } from './contracts';
import type { Messages } from '../ui';
export declare function FolderBreadcrumbs({ path, collapsed, client, portal, t, onRoot, onFolder, onBack, rootLabel }: {
    path: readonly NavigationFolder[];
    collapsed: boolean;
    client: FolderNavigationClient | null;
    onBack?: () => void;
    rootLabel?: string;
    portal: HTMLElement;
    t: Messages;
    onRoot: () => void;
    onFolder: (folder: NavigationFolder) => void;
}): import("react/jsx-runtime").JSX.Element;
