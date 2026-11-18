import type { EntryTag, EntryTagInput, StudioTransport } from '../../contracts';
import { type Messages } from '../../ui';
export declare function EntryTagField({ workspace, source, tags, value, onChange, onAdd, busy, portal, t }: {
    workspace: string;
    source: StudioTransport['browseChoices'];
    tags: EntryTag[];
    value: string;
    onChange: (value: string) => void;
    onAdd: (tag: EntryTagInput) => void;
    busy: boolean;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
