import type { EntryPage } from './contracts';
export declare function appendPage(pages: readonly EntryPage[], incoming: EntryPage, direction: 'next' | 'previous', maximum?: number): EntryPage[];
