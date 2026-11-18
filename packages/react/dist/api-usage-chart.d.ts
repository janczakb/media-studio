import type { ApiUsagePoint } from './api-access';
import type { ShareAnalytics } from './management-contracts';
export interface UsageChartOptions {
    points: ApiUsagePoint[];
    locale: string;
    requestsLabel: string;
    errorsLabel: string;
    label: string;
}
/** Separate on-demand renderer; no chart dependency enters the library's initial bundle. */
export declare function mountUsageChart(element: HTMLElement, options: UsageChartOptions): {
    update(value: UsageChartOptions): void;
    destroy(): void;
};
interface ShareChartOptions {
    points: ShareAnalytics['points'];
    locale: string;
    label: string;
    durationLabel: string;
    mode: 'area' | 'combo';
    onActiveDay: (date: string | null) => void;
}
export declare function mountShareChart(element: HTMLElement, options: ShareChartOptions): {
    destroy(): void;
};
export {};
