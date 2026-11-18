import { type ReactNode } from 'react';
export declare const browserTimeZone: () => string;
export declare function validTimeZone(value: unknown): value is string;
export declare function TimePreferences({ zone, locale, children }: {
    zone: string;
    locale: string;
    children: ReactNode;
}): import("react/jsx-runtime").JSX.Element;
export declare const useStudioTimeZone: () => string;
export declare const useStudioLocale: () => string;
export declare function useDateText(locale?: string): (value: string | null) => string;
