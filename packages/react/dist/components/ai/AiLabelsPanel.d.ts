import type { StudioTransport } from '../../contracts';
import type en from './locales/en';
export declare function AiLabelsPanel({ workspace, transport, t, onOpen }: {
    workspace: string;
    transport: NonNullable<StudioTransport['aiLabels']>;
    t: Record<keyof typeof en, string>;
    onOpen: (id: string) => void;
}): import("react/jsx-runtime").JSX.Element;
