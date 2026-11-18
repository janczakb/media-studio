import { type ReactNode } from 'react';
interface Props {
    preferenceKey: string;
    libraryScrollRef?: React.RefObject<HTMLDivElement | null>;
    scrollLibrary?: boolean;
    rail: ReactNode;
    panel: ReactNode;
    panelKey: string;
    panelLabel: string;
    panelWidth: number;
    picker: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    closeLabel: string;
    openLabel: string;
    header: (trigger: ReactNode) => ReactNode;
    children: ReactNode;
    overlays: ReactNode;
    pickerPreviewLayer?: ReactNode;
}
/** Owns geometry and disclosure. Page modules only supply content slots. */
export declare function StudioShell({ libraryScrollRef, scrollLibrary, preferenceKey, rail, panel, panelKey, panelLabel, panelWidth, picker, open, onOpenChange, closeLabel, openLabel, header, children, overlays, pickerPreviewLayer }: Props): import("react/jsx-runtime").JSX.Element;
export {};
