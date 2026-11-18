import type { Entry, StudioOptions } from '../../contracts';
import { type Messages } from '../../ui';
interface Props {
    entry: Entry;
    workspace: string;
    options: StudioOptions;
    portal: HTMLDivElement;
    t: Messages;
    editing: boolean;
    closing?: boolean;
    canTrash: boolean;
    showTrash: boolean;
    canDownload: boolean;
    canComments: boolean;
    commentsActive: boolean;
    onDownload: () => void;
    onComments: () => void;
    canDraw: boolean;
    drawingReady: boolean;
    annotationsVisible: boolean;
    hasAnnotations: boolean;
    onDraw: () => void;
    onToggleAnnotations: () => void;
    onTrash: (entry: Entry) => void;
    onChanged: (entry: Entry) => void;
    onEditing: (editing: boolean) => void;
}
export declare function PreviewTools({ entry, workspace, options, portal, t, editing, closing, canTrash, showTrash, canDownload, canComments, commentsActive, onDownload, onComments, canDraw, drawingReady, annotationsVisible, hasAnnotations, onDraw, onToggleAnnotations, onTrash, onChanged, onEditing }: Props): import("react/jsx-runtime").JSX.Element;
export {};
