import { type Folder01Icon } from '@hugeicons/core-free-icons';
import type { BrowseChoice, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
type Option = BrowseChoice & {
    icon?: typeof Folder01Icon;
};
type Props = {
    label: string;
    selected: string[];
    options?: Option[];
    source?: StudioTransport['browseChoices'] | undefined;
    workspace?: string | undefined;
    kind?: 'extensions' | 'tags' | 'tagLocales';
    locale?: string | undefined;
    multiple?: boolean;
    searchable?: boolean;
    matchAll?: boolean | undefined;
    onMatchAll?: ((value: boolean) => void) | undefined;
    onChange: (selected: string[]) => void;
    portal: HTMLDivElement;
    t: Messages;
};
export declare function ChoiceFilter({ label, selected, options, source, workspace, kind, locale, multiple, searchable, matchAll, onMatchAll, onChange, portal, t }: Props): import("react/jsx-runtime").JSX.Element;
export {};
