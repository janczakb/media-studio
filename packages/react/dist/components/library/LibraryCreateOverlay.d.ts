import { type Messages } from '../../ui';
export declare function LibraryCreateOverlay({ portal, t, upload, folder, note }: {
    portal: HTMLDivElement;
    t: Messages;
    upload: () => void;
    folder: () => void;
    note?: (() => void) | undefined;
}): import("react/jsx-runtime").JSX.Element;
