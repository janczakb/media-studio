import { type ReactNode } from 'react';
import type { BrowseQuery, Entry, StudioTransport } from '../../contracts';
import { type ApplicationFilesTransport, type LibraryScope } from '../../application-files';
export type ApplicationLibraryProps = {
    scope: LibraryScope;
    transport: StudioTransport;
    query: BrowseQuery;
    onNavigate: (entry: Entry) => void;
    toolSlot: HTMLDivElement | null;
    titleCountSlot: HTMLSpanElement | null;
};
export declare function ApplicationFilesPage({ transport, portal, locale, sourceId, folderId, search, onNavigate, renderLibrary }: {
    transport: ApplicationFilesTransport;
    portal: HTMLDivElement;
    locale: string;
    sourceId: string | null;
    folderId: string | null;
    search: string;
    onNavigate: (source: string | null, folder: string | null) => void;
    renderLibrary: (props: ApplicationLibraryProps) => ReactNode;
}): import("react/jsx-runtime").JSX.Element;
