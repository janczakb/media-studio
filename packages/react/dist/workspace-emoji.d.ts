import type { WorkspaceMessages } from './workspace-i18n';
export interface EmojiToneControl {
    element: HTMLElement;
    value: string;
    onChange: (value: string) => void;
}
export declare function mountWorkspaceEmoji(element: HTMLElement, { locale, fallbackLocale, messages, onSelect, onToneControl }: {
    locale: string;
    fallbackLocale: string;
    messages: WorkspaceMessages;
    onSelect: (emoji: string) => void;
    onToneControl: (control: EmojiToneControl | null) => void;
}): {
    destroy(): void;
};
