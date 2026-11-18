import { type DrawingCommentContext } from './DrawingComments';
import type { DrawingMark } from '../../contracts';
import { type Messages } from '../../ui';
export declare const ImageDrawingOverlay: import("react").NamedExoticComponent<{
    marks: DrawingMark[];
}>;
export declare function ImageDrawingEditor({ initial, t, busy, error, portal, onCancel, onSave, comments }: {
    comments?: DrawingCommentContext | undefined;
    initial: DrawingMark[];
    portal: HTMLDivElement;
    t: Messages;
    busy: boolean;
    error: string;
    onCancel: () => void;
    onSave: (marks: DrawingMark[]) => void;
}): import("react/jsx-runtime").JSX.Element;
