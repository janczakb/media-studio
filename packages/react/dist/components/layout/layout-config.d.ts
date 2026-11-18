import type { ReactNode } from 'react';
import type { Workspace } from '../../contracts';
import type { StudioView } from '../../studio-location';
export type StudioNavigationItem = 'library' | 'workspaces' | 'members' | 'shares' | 'notifications' | 'ai' | 'integrations';
export interface StudioPanelContext {
    view: StudioView;
    workspace: Workspace | null;
    locale: string;
    close: () => void;
    navigate: (view: StudioView) => void;
}
export interface StudioViewLayout {
    /** Omit to use the view's default panel. False removes it entirely. */
    panel?: false | 'library' | 'workspaces' | {
        id: string;
        label: string;
        render: (context: StudioPanelContext) => ReactNode;
    };
    panelWidth?: number;
    header?: boolean;
}
export interface StudioLayoutOptions {
    /** Disable the lazy folder tree in the standard library sidebar. */
    folderTree?: boolean;
    folderTreeOptions?: import('../../folders/FolderTreeClient').FolderTreeOptions;
    /** Presentation only. Server permissions still decide access. */
    navigation?: false | {
        items?: StudioNavigationItem[];
    };
    panelWidth?: number;
    views?: Partial<Record<StudioView, StudioViewLayout>>;
}
export declare function resolveViewLayout(view: StudioView, options?: StudioLayoutOptions, picker?: boolean): {
    panel: false | "workspaces" | "library" | {
        id: string;
        label: string;
        render: (context: StudioPanelContext) => ReactNode;
    };
    panelWidth: number;
    header: boolean;
    rail: boolean;
};
