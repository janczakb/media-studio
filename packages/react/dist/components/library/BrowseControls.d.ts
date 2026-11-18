import type { BrowseDirection, BrowseExtensions, BrowseFilters, BrowseSort, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
type Props = {
    transport?: StudioTransport;
    workspace?: string;
    filters: BrowseFilters;
    customFilters: Record<string, string>;
    extensions: BrowseExtensions;
    sort: BrowseSort;
    direction: BrowseDirection;
    portal: HTMLDivElement;
    t: Messages;
    apply: (filters: BrowseFilters, custom: Record<string, string>) => void;
    order: (sort: BrowseSort, direction: BrowseDirection) => void;
};
export declare function BrowseControls(props: Props): import("react/jsx-runtime").JSX.Element;
type FilterProps = Pick<Props, 'filters' | 'customFilters' | 'extensions' | 'portal' | 't' | 'apply' | 'transport' | 'workspace'> & {
    uploaders?: boolean;
    searchPanel?: boolean;
};
/** Measure the actual translated chips against the toolbar, including its right controls. */
export declare function FilterControls(props: FilterProps): import("react/jsx-runtime").JSX.Element;
export declare function FilterItems({ filters, customFilters, extensions, portal, t, apply, transport, workspace, uploaders, searchPanel }: FilterProps): import("react/jsx-runtime").JSX.Element;
export declare function SortControl({ extensions, sort, direction, portal, t, order }: Pick<Props, 'extensions' | 'sort' | 'direction' | 'portal' | 't' | 'order'>): import("react/jsx-runtime").JSX.Element;
export {};
