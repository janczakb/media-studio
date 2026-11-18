import type { CommentTransport } from '../../contracts';
import { type Messages } from '../../ui';
export declare function FileComments({ workspace, entry, transport, t, portal, controls }: {
    workspace: string;
    entry: string;
    transport: CommentTransport;
    t: Messages;
    portal: HTMLDivElement;
    controls: HTMLDivElement | null;
}): import("react/jsx-runtime").JSX.Element | null;
