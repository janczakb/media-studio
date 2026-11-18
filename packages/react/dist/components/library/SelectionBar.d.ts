import type { Folder01Icon } from '@hugeicons/core-free-icons';
export type SelectionAction = {
    id: string;
    label: string;
    icon: typeof Folder01Icon;
    run: () => void;
    danger?: boolean;
    disabled?: boolean;
};
export declare function SelectionBar({ label, count, actions, portal }: {
    label: string;
    count: string;
    actions: SelectionAction[];
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
