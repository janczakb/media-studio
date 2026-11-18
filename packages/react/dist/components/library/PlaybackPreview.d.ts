import type { Entry } from '../../contracts';
import type { Messages } from '../../ui';
export declare function loadVideoPlayer(): Promise<unknown>;
export declare function PlaybackPreview({ entry, url, t, skinVariant, active, onDimensions }: {
    entry: Entry;
    url: string;
    t: Messages;
    skinVariant?: 'default' | 'neutral';
    active?: boolean;
    onDimensions?: (width: number, height: number, duration: number) => void;
}): import("react/jsx-runtime").JSX.Element;
