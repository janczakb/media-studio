export declare const officePreviewFormats: readonly ["docx", "xlsx", "pptx", "pages", "key", "numbers", "doc", "xls", "ppt", "odt", "ods", "odp", "rtf", "csv", "md", "txt"];
export type OfficePreviewFormat = typeof officePreviewFormats[number];
export interface OfficePreviewSettings {
    /** Show the document outline toggle. Hidden by default. */
    outline?: boolean;
    /** Header controls by default; native floating controls or no zoom UI are optional. */
    zoomControls?: 'header' | 'floating' | false;
    /** Custom search action for text documents and active worksheets. */
    search?: boolean;
    print?: boolean;
    formats?: readonly OfficePreviewFormat[];
    /** CSV resident cell budget; rows beyond this budget are requested on demand. */
    residentCells?: number;
    pageRows?: number;
    maxBytes?: number;
    timeoutMs?: number;
}
export interface OfficePreviewMount {
    url: string;
    name: string;
    residentCells: number;
    pageRows: number;
    theme: 'light' | 'dark';
    outline: boolean;
    search: OfficeFindState;
    floatingZoom: boolean;
    viewport: OfficeViewportControl;
    onViewport: (state: OfficeViewportState) => void;
    onSearchResult: (result: OfficeFindResult) => void;
    onProgress: (progress: {
        phase: string;
        loaded: number;
        total: number;
    }) => void;
    onReady: () => void;
    onError: () => void;
}
export interface OfficeFindState {
    open: boolean;
    query: string;
    step: number;
    direction: 1 | -1;
}
export interface OfficeFindResult {
    index: number;
    total: number;
}
export interface OfficePreviewHandle {
    print(): Promise<void>;
    destroy(): void;
    search(state: OfficeFindState): void;
    viewport(state: OfficeViewportControl): void;
}
export interface OfficeViewportState {
    page: number;
    total: number;
    zoom: number;
    fit: boolean;
    minZoom?: number;
    maxZoom?: number;
}
export interface OfficeViewportControl {
    page?: {
        value: number;
        request: number;
    };
    zoom?: {
        value: number | 'fit';
        request: number;
        center?: boolean;
    };
}
