import type {BrowseQuery, Entry, EntryDetails, EntryPage, StudioTransport, Workspace} from './contracts';

export type ApplicationSource = {id: string; name: string; type: 'disk' | 'model' | 'spatie'; readOnly: true};
export type ApplicationFile = EntryDetails & {readOnly: true; downloadUrl: string | null};
export type ApplicationFilePage = EntryPage & {readOnly: true};
export interface ApplicationFilesTransport {
  sources(signal: AbortSignal): Promise<{enabled: boolean; canDownload: boolean; items: ApplicationSource[]}>;
  browse(source: string | null, query: BrowseQuery, cursor: string | null, signal: AbortSignal): Promise<ApplicationFilePage>;
  details(source: string, entry: string, signal: AbortSignal): Promise<ApplicationFile>;
  neighbors(source: string | null, entry: string, query: BrowseQuery, direction: 'next' | 'previous', signal: AbortSignal, cursor?: string): Promise<EntryPage>;
  thumbnailStatus?(ids: string[], signal: AbortSignal): Promise<{items: Entry[]}>;
  contentUrl(source: string, entry: string, download?: boolean): string;
}

/** Presentation context only. No workspace is created, resolved or persisted. */
export type LibraryScope = Omit<Workspace, 'isDefault'> & {kind?: 'workspace' | 'application'};

export function applicationLibraryTransport(api: ApplicationFilesTransport, source: string | null): StudioTransport {
  const readOnly = async (): Promise<never> => {throw new Error('Application files are read-only.');};
  return {
    browse: (_scope, query, cursor, signal) => api.browse(source, query, cursor, signal),
    details: (_scope, entry, signal) => api.details(source ?? '', entry, signal),
    neighbors: (_scope, entry, query, direction, signal, cursor) => api.neighbors(source, entry, query, direction, signal, cursor),
    contentUrl: (_scope, entry, download) => api.contentUrl(source ?? '', entry, download),
    status: (_scope, ids, signal) => api.thumbnailStatus?.(ids, signal) ?? Promise.resolve({items: []}),
    workspaces: async () => ({items: [], canCreate: false}),
    createWorkspace: readOnly, createFolder: readOnly, upload: readOnly,
    update: readOnly, trash: readOnly, restore: readOnly,
  };
}
