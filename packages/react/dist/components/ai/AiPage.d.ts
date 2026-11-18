import type { AiConfiguration, StudioTransport } from '../../contracts';
export declare function AiPage({ info, error, onRetry, locale, portal, workspace, transport, onOpen, initialSection }: {
    initialSection?: 'api' | 'connection';
    workspace: string | undefined;
    transport: StudioTransport;
    onOpen: (id: string) => void;
    info: AiConfiguration | null;
    error: string;
    onRetry: () => void;
    locale: string;
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
