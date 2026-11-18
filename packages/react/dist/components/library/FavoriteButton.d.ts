import type { Entry } from '../../contracts';
import { type Messages } from '../../ui';
export type ToggleFavorite = (entry: Entry, onChanged?: (starred: boolean) => void) => void;
export declare function FavoriteButton({ entry, t, onToggle, pending, className, iconSize, iconStrokeWidth }: {
    entry: Entry;
    t: Messages;
    onToggle: () => void;
    pending?: boolean;
    className?: string;
    iconSize?: number;
    iconStrokeWidth?: number;
}): import("react/jsx-runtime").JSX.Element;
