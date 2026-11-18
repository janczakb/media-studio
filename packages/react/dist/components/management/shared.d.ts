import { type TableEmptyKind } from '../TableEmptyState';
import { type ReactNode } from 'react';
import type { CursorPage } from '../../management-contracts';
import type en from './locales/en';
export type ManagementLocale = string;
export type ManagementMessages = Record<keyof typeof en, string>;
export declare const useManagementMessages: () => ManagementMessages;
export declare const dateText: (value: string | null, locale: ManagementLocale) => string;
export declare function useCursorPage<T, P extends CursorPage<T>>(loader: ((cursor: string | null, signal: AbortSignal) => Promise<P>) | undefined, identity: string): {
    data: P | null;
    error: string;
    pending: boolean;
    available: boolean;
    cursor: string | null;
    pageNumber: number;
    setCursor: (next: string) => void;
    refresh: () => void;
};
export declare function ManagementTabs({ label, value, options, onChange }: {
    label: string;
    value: string;
    options: readonly (readonly [string, string])[];
    onChange: (value: string) => void;
}): import("react/jsx-runtime").JSX.Element;
export declare function ManagementSelect({ label, value, options, onChange, portal }: {
    label: string;
    value: string;
    options: readonly (readonly [string, string])[];
    onChange: (value: string) => void;
    portal: HTMLElement;
}): import("react/jsx-runtime").JSX.Element;
export declare function PageFrame({ title, hint, actions, children }: {
    title: string;
    hint: string;
    actions?: ReactNode;
    children: ReactNode;
}): import("react/jsx-runtime").JSX.Element;
export declare function ListState({ pending, error, empty, available, retry, t, kind }: {
    kind?: TableEmptyKind;
    pending: boolean;
    error: string;
    empty: string;
    available: boolean;
    retry: () => void;
    t: ManagementMessages;
}): import("react/jsx-runtime").JSX.Element;
export declare function CursorFooter({ previous, next, busy, onPage, refresh, t, pageNumber }: {
    previous?: string | null;
    next: string | null;
    busy: boolean;
    onPage: (cursor: string) => void;
    refresh: () => void;
    t: ManagementMessages;
    pageNumber?: number;
}): import("react/jsx-runtime").JSX.Element;
export declare function VirtualRows<T>({ items, getKey, render, identity, onVisible, rowHeight }: {
    items: T[];
    getKey: (item: T) => string;
    render: (item: T) => ReactNode;
    identity: string;
    onVisible?: (items: T[]) => void;
    rowHeight?: number;
}): import("react/jsx-runtime").JSX.Element;
export declare function ConfirmDialog({ portal, title, hint, confirm, busy, error, onConfirm, onClose, t, danger }: {
    portal: HTMLElement;
    title: string;
    hint: string;
    confirm: string;
    busy: boolean;
    error: string;
    onConfirm: () => void;
    onClose: () => void;
    t: ManagementMessages;
    danger?: boolean;
}): import("react/jsx-runtime").JSX.Element;
export declare function DateText({ value, locale }: {
    value: string | null;
    locale: string;
}): import("react/jsx-runtime").JSX.Element;
