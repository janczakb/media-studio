import { type Messages } from '../../ui';
export type LibraryLayout = 'grid' | 'large-grid' | 'list';
export declare function LayoutControl({ layout, onChange, portal, t }: {
    layout: LibraryLayout;
    onChange: (value: LibraryLayout) => void;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
export declare function UploadMenu({ upload, folder, portal, t }: {
    upload: () => void;
    folder?: (() => void) | undefined;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
export declare function CreateMenu({ createFolder, note, portal, t }: {
    note?: (() => void) | undefined;
    createFolder: () => void;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
/** The compact menu preserves the same capability-gated callbacks as the expanded pair. */
export declare function LibraryCreationActions({ upload, uploadFolder, createFolder, note, portal, t }: {
    upload?: (() => void) | undefined;
    uploadFolder?: (() => void) | undefined;
    createFolder: () => void;
    note?: (() => void) | undefined;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
