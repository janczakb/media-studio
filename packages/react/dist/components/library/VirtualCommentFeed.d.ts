import { type ReactNode } from 'react';
import type { CommentPage, FileComment } from '../../contracts';
import { type Messages } from '../../ui';
/** Cursor history is lightweight; only eight pages of comment/profile payloads are retained. */
export declare function VirtualCommentFeed({ load, render, t, onPage, onDenied }: {
    load: (cursor: string | null, signal: AbortSignal) => Promise<CommentPage>;
    render: (comment: FileComment) => ReactNode;
    t: Messages;
    onPage: (page: CommentPage) => void;
    onDenied: () => void;
}): import("react/jsx-runtime").JSX.Element;
