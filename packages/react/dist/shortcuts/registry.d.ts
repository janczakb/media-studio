/** Stable command IDs are independent of translated labels and keyboard layout. */
export declare const shortcutDefaults: {
    readonly search: "Mod+K";
    readonly upload: "Mod+Shift+U";
    readonly newFolder: "Alt+Shift+N";
    readonly settings: "Alt+Shift+S";
    readonly selectAll: "Mod+A";
    readonly clearSelection: "Escape";
    readonly copyImage: "Mod+C";
    readonly pasteFiles: "Mod+V";
};
export type ShortcutCommand = keyof typeof shortcutDefaults;
export type ShortcutBindings = Partial<Record<ShortcutCommand, string | null>>;
export interface ShortcutOptions {
    enabled?: boolean;
    customizable?: boolean;
    bindings?: ShortcutBindings;
    clipboard?: false | {
        paste?: boolean;
        copyImages?: boolean;
        maxFiles?: number;
        maxBytes?: number;
        maxPixels?: number;
    };
}
export declare function normalizeShortcut(value: unknown): string | null;
export declare function shortcutSignature(binding: string, mac: boolean): string;
export declare function eventShortcut(event: Pick<KeyboardEvent, 'key' | 'code' | 'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey'>): string | null;
/** Identify browser/OS chords so the recorder can request explicit conflict override. */
export declare function reservedShortcut(binding: string, mac: boolean): boolean;
export declare function resolveShortcutBindings(defaults?: ShortcutBindings, overrides?: ShortcutBindings, mac?: boolean): Record<ShortcutCommand, string | null>;
export declare function readShortcutPreferences(raw: string | null): ShortcutBindings;
export declare function editableShortcutPath(path: EventTarget[]): boolean;
export type ShortcutHandler = () => boolean | void;
export declare class ShortcutRegistry {
    readonly root: HTMLElement;
    readonly mac: boolean;
    private handlers;
    private coordinator;
    bindings: Record<ShortcutCommand, string | null>;
    enabled: boolean;
    onPaste: ((event: ClipboardEvent) => boolean) | undefined;
    constructor(root: HTMLElement, mac: boolean, defaults?: ShortcutBindings);
    register(id: ShortcutCommand, handler: ShortcutHandler, enabled?: () => boolean): () => void;
    available(event: Event): boolean;
    keydown(event: KeyboardEvent): void;
    paste(event: ClipboardEvent): void;
    destroy(): void;
}
