import { type ReactNode } from 'react';
import type { AssistantMessage } from './contracts';
/** End anchoring preserves the visible message through prepend, trimming and live resizing. */
export declare function VirtualAssistantMessages({ messages, render, stream, older, newer, busy, error, load, pl }: {
    messages: AssistantMessage[];
    render: (item: AssistantMessage) => ReactNode;
    stream: ReactNode;
    older: boolean;
    newer: boolean;
    busy: boolean;
    error: boolean;
    load: (direction: 'older' | 'newer') => void;
    pl: boolean;
}): import("react/jsx-runtime").JSX.Element;
