import type { Entry, StudioOptions, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
/** An inline drill-down, never a second modal or a document-level portal. */
export declare function PickerPreview({ entry, workspace, transport, t, onClose, onSelect, documentPreview, pdfPreview, portal, theme }: {
    theme?: 'light' | 'dark';
    entry: Entry;
    workspace: string;
    transport: StudioTransport;
    t: Messages;
    onClose: () => void;
    onSelect: StudioOptions['onSelect'];
    pdfPreview: StudioOptions['pdfPreview'];
    documentPreview: StudioOptions['documentPreview'];
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
