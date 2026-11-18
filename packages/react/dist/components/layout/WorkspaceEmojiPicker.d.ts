export declare function WorkspaceMark({ emoji, size }: {
    emoji?: string | null | undefined;
    size?: number;
}): import("react/jsx-runtime").JSX.Element;
export declare function WorkspaceEmojiPicker({ value, onChange, portal, disabled }: {
    value: string | null;
    onChange: (emoji: string | null) => void;
    portal: HTMLElement;
    disabled?: boolean;
}): import("react/jsx-runtime").JSX.Element;
