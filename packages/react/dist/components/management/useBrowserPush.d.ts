import { type BrowserPushSettings, type BrowserPushTransport, type PushEvent } from '../../browser-push';
import { type Messages } from '../../ui';
export declare function useBrowserPush(transport: BrowserPushTransport | undefined, workspace: string | undefined, visible: boolean, t: Messages, locale: string): {
    settings: BrowserPushSettings | null;
    busy: boolean;
    error: string;
    supported: boolean;
    subscribed: boolean;
    hint: string;
    toggle: (enabled: boolean) => Promise<void>;
    changeEvents: (events: PushEvent[]) => Promise<void>;
    refresh: () => void;
};
export type BrowserPushControl = ReturnType<typeof useBrowserPush>;
