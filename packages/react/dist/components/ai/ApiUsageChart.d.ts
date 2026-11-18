import type { ApiUsagePoint } from '../../api-access';
import type en from './locales/en';
export declare function ApiUsageChart({ points, t, locale }: {
    points: ApiUsagePoint[];
    t: Record<keyof typeof en, string>;
    portal: HTMLDivElement;
    locale: string;
}): import("react/jsx-runtime").JSX.Element;
