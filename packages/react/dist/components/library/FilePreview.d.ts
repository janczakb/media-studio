import type { BrowseQuery, Entry, MediaReference, StudioOptions } from '../../contracts';
import { type Messages } from '../../ui';
import { type EntryActionHandlers } from './EntryActions';
import { type ToggleFavorite } from './FavoriteButton';
export interface PreviewAnchor {
    entry: Entry;
    rect: DOMRect | null;
    source: HTMLImageElement | null;
}
type PreviewProps = {
    anchor: PreviewAnchor | null;
    workspace: string;
    query: BrowseQuery;
    options: StudioOptions;
    portal: HTMLDivElement;
    t: Messages;
    onNavigateEntry?: (entry: Entry) => void;
    onClose: () => void;
    onChanged: (entry: Entry) => void;
    onTrash: (entry: Entry) => void;
    canWrite: boolean;
    actions?: EntryActionHandlers;
    allowShare?: boolean;
    guestUploads?: boolean;
    allowDownload?: boolean;
    allowTrash?: boolean;
    showTrash?: boolean;
    toggleFavorite: ToggleFavorite | undefined;
    pendingFavorites: ReadonlySet<string>;
    inlineTarget?: HTMLDivElement;
    pickerSelect?: ((entry: Entry) => boolean) | undefined;
    pickerSelection?: ReadonlyMap<string, MediaReference> | undefined;
    pickerSelectionFull?: boolean;
};
export declare function FilePreview({ anchor, ...props }: PreviewProps): import("react/jsx-runtime").JSX.Element | null;
export {};
