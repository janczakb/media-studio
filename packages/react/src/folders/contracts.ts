export interface NavigationFolder {
  id: string;
  name: string;
  readOnly?: boolean;
}
export interface FolderContext {
  folderId: string;
  root: {id: null};
  tail: NavigationFolder[];
  hasCollapsedAncestors: boolean;
  ancestorCursor: string | null;
  totalDepth: number | null;
  revision: number;
}
export interface AncestorPage {
  /** Nearest ancestors first. */
  items: NavigationFolder[];
  nextCursor: string | null;
  revision: number;
}
export interface NavigationReceipt {operationId?: string; undoAvailable?: boolean}
export interface NavigationChangePage {items: {revision: number; kind: string; payload: Record<string, unknown>}[]; revision: number; nextRevision: number; hasMore: boolean; reset: boolean}
export interface FolderNavigationTransport {
  changes?(workspace: string, after: number, signal: AbortSignal): Promise<NavigationChangePage>;
  undo?(workspace: string, operation: string, signal: AbortSignal): Promise<{undone: boolean}>;
  /** Optional host push adapter; messages only invalidate, authenticated changes are fetched separately. */
  subscribe?(workspace: string, listener: () => void): () => void;
  context(workspace: string, folder: string, signal: AbortSignal): Promise<FolderContext>;
  ancestors(workspace: string, folder: string, cursor: string, limit: number, signal: AbortSignal): Promise<AncestorPage>;
}
