import type { Entry } from '../../contracts';
import type { PhotoPeriod } from './PhotosToolbar';
export type TimelineRow = {
    key: string;
    entries: Entry[];
    label?: string;
    height: number;
    start: number;
};
export declare function timelineRows(items: Entry[], columns: number, cell: number, period: PhotoPeriod, locale?: string): TimelineRow[];
