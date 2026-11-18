import type { LibrarySearchQuery, LibrarySearchResult, LibrarySearchTransport } from './contracts';
/** Manages one authorized search scope and ignores responses from superseded queries. */
export declare function useLibrarySearch(transport: LibrarySearchTransport, workspace: string, query: LibrarySearchQuery, enabled: boolean, debounce?: number): {
    loadMore: () => void;
    retry: () => void;
    key: string;
    items: LibrarySearchResult[];
    cursor: string | null;
    loading: boolean;
    settled: boolean;
    failed: boolean;
    limited: boolean;
};
