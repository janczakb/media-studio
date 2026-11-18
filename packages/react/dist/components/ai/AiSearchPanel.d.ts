import type { StudioTransport } from '../../contracts';
import type en from './locales/en';
export declare function AiSearchPanel({ workspace, search, semantic, t, portal, onOpen }: {
    workspace: string;
    search: NonNullable<StudioTransport['searchIndex']>;
    semantic: boolean;
    t: Record<keyof typeof en, string>;
    portal: HTMLDivElement;
    onOpen: (id: string) => void;
}): import("react/jsx-runtime").JSX.Element;
