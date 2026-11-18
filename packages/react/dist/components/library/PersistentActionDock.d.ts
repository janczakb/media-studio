import { type ReactNode } from 'react';
export declare function PersistentActionDock({ children, content, width, label, enabled }: {
    children: ReactNode;
    content: ReactNode;
    width: number;
    label: string;
    enabled?: boolean;
}): import("react/jsx-runtime").JSX.Element;
export declare function PreviewDockOverride({ children, width, visible, closing }: {
    children: ReactNode;
    width: number;
    visible: boolean;
    closing?: boolean;
}): import("react/jsx-runtime").JSX.Element;
