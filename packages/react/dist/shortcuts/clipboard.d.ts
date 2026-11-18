export interface ClipboardLimits {
    maxFiles: number;
    maxBytes: number;
    maxPixels: number;
}
export declare const clipboardDefaults: ClipboardLimits;
export declare function clipboardLimits(values?: Partial<ClipboardLimits>): ClipboardLimits;
export declare function clipboardFiles(data: DataTransfer, limits: ClipboardLimits): File[];
/** Called only from an explicit copy command; never reads the clipboard in the background. */
export declare function copyImageToClipboard(url: string, limits: ClipboardLimits, signal: AbortSignal): Promise<void>;
export declare function readClipboardFiles(limits: ClipboardLimits): Promise<File[]>;
