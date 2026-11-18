import type { CommentTransport, DrawingPoint } from '../../contracts';
import { type Messages } from '../../ui';
export interface DrawingCommentContext {
    workspace: string;
    entry: string;
    versionId: string;
    transport: CommentTransport;
    viewer?: {
        name?: string;
        avatarUrl?: string;
    } | undefined;
}
export declare function DrawingComments({ context, point, onPoint, onReady, t }: {
    context: DrawingCommentContext;
    point: DrawingPoint | null;
    onPoint: (point: DrawingPoint | null) => void;
    onReady: (allowed: boolean) => void;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
