import type { OfficePreviewMount, OfficePreviewHandle } from './office-preview-contract';
/** Lazy reader root. The host owns the worker even while open() is pending. */
export declare function mountOfficePreview(host: HTMLElement, options: OfficePreviewMount): OfficePreviewHandle;
