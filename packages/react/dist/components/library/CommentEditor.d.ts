import type { CommentRun } from '../../contracts';
export interface CommentEditorHandle {
    focus(): void;
    insertMention(person: {
        id: string;
        principalType: string;
        name: string | null;
    }): void;
    replace(runs: CommentRun[]): void;
}
/** Tiny plain-text editor with atomic identity chips; pasted HTML is never imported. */
export declare const CommentEditor: import("react").ForwardRefExoticComponent<{
    label: string;
    placeholder: string;
    disabled: boolean;
    listId: string;
    activeId: string | undefined;
    onChange: (body: string, runs: CommentRun[], query: string | undefined) => void;
    onKeyDown: React.KeyboardEventHandler<HTMLDivElement>;
} & import("react").RefAttributes<CommentEditorHandle>>;
