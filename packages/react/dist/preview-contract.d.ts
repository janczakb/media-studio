import type { Entry } from './contracts';
import type { OfficeViewportState } from './office-preview-contract';
export type PreviewPanel = 'information' | 'comments' | 'assistant' | 'none';
export interface FilePreviewHandle {
    getEntry(): Entry;
    getViewport(): OfficeViewportState;
    close(): void;
    navigate(direction: 'previous' | 'next'): Promise<void>;
    setZoom(value: number | 'fit'): void;
    showPanel(panel: PreviewPanel): boolean;
    search(query: string): boolean;
    print(): Promise<boolean>;
    rename(name: string, signal: AbortSignal): Promise<Entry>;
}
