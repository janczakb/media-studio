import { type Messages } from '../ui';
/** Legacy transports keep their existing list-search semantics in the same overlay. */
export declare function BasicSearchSpotlight({ open, onOpenChange, value, onChange, portal, t }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: string;
    onChange: (value: string) => void;
    portal: HTMLDivElement;
    t: Messages;
}): import("react/jsx-runtime").JSX.Element;
