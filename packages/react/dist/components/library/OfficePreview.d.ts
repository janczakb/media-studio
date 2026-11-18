import type { Entry } from '../../contracts';
import type { Messages } from '../../ui';
import { type OfficePreviewSettings, type OfficeFindState, type OfficeFindResult, type OfficeViewportControl, type OfficeViewportState } from '../../office-preview-contract';
export declare function supportsOfficePreview(entry: Entry, settings?: false | OfficePreviewSettings): boolean;
export declare function supportsOfficePages(entry: Entry, settings?: false | OfficePreviewSettings): boolean;
export declare function supportsOfficeSearch(entry: Entry, settings?: false | OfficePreviewSettings): boolean;
export declare function OfficePreview({ entry, url, t, active, settings, theme, search, onSearchResult, viewport, onViewport, onPrintReady }: {
    onPrintReady?: (print: (() => Promise<void>) | undefined) => void;
    entry: Entry;
    url: string;
    t: Messages;
    active?: boolean;
    settings?: OfficePreviewSettings;
    theme?: 'light' | 'dark';
    viewport?: OfficeViewportControl | undefined;
    onViewport?: (state: OfficeViewportState) => void;
    search?: OfficeFindState | undefined;
    onSearchResult?: (result: OfficeFindResult) => void;
}): import("react/jsx-runtime").JSX.Element;
