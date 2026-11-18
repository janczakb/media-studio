import type { OfficeViewportControl, OfficeViewportState } from '../../office-preview-contract';
import { type Messages } from '../../ui';
export declare function useDocumentViewport(key: string): {
    state: OfficeViewportState;
    control: OfficeViewportControl | undefined;
    onViewport: (state: OfficeViewportState) => void;
    changeViewport: (command: OfficeViewportControl) => void;
};
export declare function DocumentPageControl({ state, onChange, t }: {
    state: OfficeViewportState;
    onChange: (command: OfficeViewportControl) => void;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element | null;
export declare function DocumentZoomControls({ state, onChange, t, portal, minZoom, maxZoom, steps, multiplicative, actualSizeOnly }: {
    state: OfficeViewportState;
    onChange: (command: OfficeViewportControl) => void;
    t: Messages;
    portal: HTMLDivElement;
    minZoom?: number;
    maxZoom?: number;
    steps?: number[];
    multiplicative?: boolean;
    actualSizeOnly?: boolean;
}): import("react/jsx-runtime").JSX.Element | null;
