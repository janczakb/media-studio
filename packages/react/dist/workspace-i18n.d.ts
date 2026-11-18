import { type ReactNode } from 'react';
import type { Messages } from './ui';
import { workspaceMessageKeys } from './studio-locales';
export type StudioNamespaces = {
    management: Record<keyof typeof import('./components/management/locales/en').default, string>;
    ai: Record<keyof typeof import('./components/ai/locales/en').default, string>;
    integrations: Record<keyof typeof import('./components/integrations/locales/en').default, string>;
};
export type WorkspaceMessages = Record<(typeof workspaceMessageKeys)[number], string>;
export interface StudioLocaleCatalog {
    label?: string;
    messages?: Partial<Messages>;
    workspace?: Partial<WorkspaceMessages>;
    namespaces?: {
        [K in keyof StudioNamespaces]?: Partial<StudioNamespaces[K]>;
    };
    load?: () => Promise<Omit<StudioLocaleCatalog, 'load'>>;
}
export interface StudioLocalization {
    catalogs?: Record<string, StudioLocaleCatalog>;
    bootstrap?: {
        loading?: string;
        retry?: string;
    };
}
export interface LoadedStudioLocale {
    locale: string;
    fallbackLocale: string;
    messages: Messages;
    workspace: WorkspaceMessages;
    namespaces: StudioNamespaces;
}
export interface LocalizationState extends LoadedStudioLocale {
    pending: boolean;
    requestLocale: (locale: string) => void;
}
export declare const defaultLocale = "en";
export declare function canonicalLocale(value: unknown): string | undefined;
/** Exact region, language/script, then base language. Never build paths from raw input. */
export declare function localeCandidates(value: string): string[];
export declare function resolveStudioLocale(...values: unknown[]): string;
export declare function loadStudioLocale(locale: string, options?: StudioLocalization): Promise<LoadedStudioLocale>;
export declare function studioLanguageOptions(locale: string, options?: StudioLocalization): {
    id: string;
    label: string;
}[];
export declare function StudioLocalizationProvider({ value, children }: {
    value: LocalizationState;
    children: ReactNode;
}): import("react/jsx-runtime").JSX.Element;
export declare function useStudioLocalization(): LocalizationState;
export declare function useStudioNamespace<K extends keyof StudioNamespaces>(name: K): StudioNamespaces[K];
export declare function useWorkspaceMessages(): {
    locale: string;
    fallbackLocale: string;
    messages: WorkspaceMessages;
    tr: (key: keyof WorkspaceMessages, values?: Record<string, string | number>) => string;
};
