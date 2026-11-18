import type {BrowseFilters, Entry} from '../contracts';

export type LibrarySearchMode = 'exact' | 'index' | 'hybrid' | 'semantic';

export interface LibrarySearchQuery {
  text: string;
  filters?: BrowseFilters;
  customFilters?: Record<string, string>;
  mode?: LibrarySearchMode;
  kind?: 'file' | 'folder';
  limit?: number;
}

export type LibrarySearchResult = Entry & {parentName?: string | null};

export interface LibrarySearchPage {
  items: LibrarySearchResult[];
  nextCursor: string | null;
  hasMore: boolean;
}

export type LibrarySearchTransport = (
  workspace: string,
  query: LibrarySearchQuery,
  cursor: string | null,
  signal: AbortSignal,
) => Promise<LibrarySearchPage>;
