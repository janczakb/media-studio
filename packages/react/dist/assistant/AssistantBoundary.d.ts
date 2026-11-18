import type { AssistantProfile, AssistantAction } from './contracts';
import { type ReactNode } from 'react';
import type { AssistantConfiguration, AssistantTransport } from './contracts';
export declare function AssistantBoundary({ children, configuration, transport, workspace, file, folder, portal, locale, identity, onMutation, onAction, userName }: {
    children: ReactNode;
    configuration: AssistantConfiguration | undefined;
    transport: AssistantTransport | undefined;
    workspace: string | null;
    file: string | null;
    folder: string | null;
    portal: HTMLDivElement;
    locale: string;
    identity: string;
    onMutation: () => void;
    onAction: (action: AssistantAction) => void;
    userName: string;
}): import("react/jsx-runtime").JSX.Element;
export declare function AssistantToggle({ rail, locale }: {
    rail?: boolean;
    locale?: string;
}): import("react/jsx-runtime").JSX.Element | null;
export declare function AssistantPreviewSlot(): import("react/jsx-runtime").JSX.Element | null;
export declare function useAssistantPreview(): {
    enabled: boolean;
    toggle: () => void;
    showHistory: () => void;
    historyOpen: boolean;
    open: boolean;
    close: () => void;
    setTarget: (node: HTMLDivElement | null) => void;
    panelWidth: number;
    setPanelWidth: (width: number) => void;
    profile: AssistantProfile;
    setProfile: (profile: AssistantProfile) => void;
};
