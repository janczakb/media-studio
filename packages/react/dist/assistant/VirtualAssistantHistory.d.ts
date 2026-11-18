import { type ReactNode } from 'react';
import type { AssistantConversation } from './contracts';
/** Six payload pages retained; lightweight cursor checkpoints allow revisiting evicted pages. */
export declare function VirtualAssistantHistory({ load, render, pl }: {
    load: (cursor: string | null, signal: AbortSignal) => Promise<{
        items: AssistantConversation[];
        nextCursor: string | null;
    }>;
    render: (item: AssistantConversation) => ReactNode;
    pl: boolean;
}): import("react/jsx-runtime").JSX.Element;
