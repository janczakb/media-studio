import type { AiConfiguration } from '../../contracts';
import type en from './locales/en';
type Messages = Record<keyof typeof en, string>;
export declare function AiConnection({ mcp, t, portal, copied, copy, onTools }: {
    mcp: AiConfiguration['mcp'];
    t: Messages;
    portal: HTMLDivElement;
    copied: string | null;
    copy: (value: string) => Promise<void>;
    onTools: () => void;
}): import("react/jsx-runtime").JSX.Element;
export {};
