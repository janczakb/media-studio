import type { Entry } from '../../contracts';
import type { Messages } from '../../ui';
import type { OfficeViewportControl, OfficeViewportState, OfficeFindState, OfficeFindResult } from '../../office-preview-contract';
export interface PDFPreviewSettings {
    maxBytes?: number;
    maxPages?: number;
    timeoutMs?: number;
}
export declare function supportsPDFPreview(entry: Entry, settings: false | PDFPreviewSettings | undefined): boolean;
export declare function PDFPreview({ entry, url, t, active, settings, viewport, onViewport, search, onSearchResult, onPrintReady }: {
    search?: OfficeFindState | undefined;
    onSearchResult?: (value: OfficeFindResult) => void;
    onPrintReady?: (print: (() => Promise<void>) | undefined) => void;
    entry: Entry;
    url: string;
    t: Messages;
    active?: boolean;
    settings?: PDFPreviewSettings;
    viewport: OfficeViewportControl | undefined;
    onViewport: (state: OfficeViewportState) => void;
}): import("react/jsx-runtime").JSX.Element;
