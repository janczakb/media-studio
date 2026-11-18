import type { Entry } from './contracts';
/** Decimal display units; exact byte counts remain available on the resource. */
export declare function formatFileBytes(bytes: number | null, locale?: string): string;
export declare function fileNameParts(entry: Pick<Entry, 'name' | 'kind' | 'mediaType'>): {
    title: string;
    suffix: string;
};
export declare function fileCaption(entry: Pick<Entry, 'name' | 'mimeType' | 'size' | 'width' | 'height'>, locale?: string): string;
