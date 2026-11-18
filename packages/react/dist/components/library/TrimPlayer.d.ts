import { type RefObject } from 'react';
import type { VideoPlayerElement } from '@videojs/html/video';
/** The editor uses the same Video.js skin as normal video preview. */
export declare function TrimPlayer({ showControls, source, name, media, controller, onDuration, onTime, onPlaying, onError }: {
    showControls: boolean;
    source: string;
    name: string;
    media: RefObject<HTMLVideoElement | null>;
    controller: RefObject<VideoPlayerElement | null>;
    onDuration: (duration: number) => void;
    onTime: (time: number) => void;
    onPlaying: (playing: boolean) => void;
    onError: () => void;
}): import("react").ReactElement<{
    className: string;
    ref: (node: VideoPlayerElement | null) => void;
    'aria-label': string;
}, string | import("react").JSXElementConstructor<any>> | null;
