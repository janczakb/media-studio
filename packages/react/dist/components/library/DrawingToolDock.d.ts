import { type Messages } from '../../ui';
export type DrawingTool = 'select' | 'pen' | 'erase' | 'arrow' | 'text' | 'rectangle' | 'ellipse' | 'line' | 'comment';
export declare function DrawingToolDock({ value, onChange, busy, canComment, showComments, t, portal }: {
    value: DrawingTool;
    onChange: (tool: DrawingTool) => void;
    busy: boolean;
    canComment: boolean;
    showComments: boolean;
    t: Messages;
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
