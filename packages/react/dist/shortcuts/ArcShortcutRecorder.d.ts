import type { HTMLAttributes, ReactNode } from "react";
export type Platform = "mac" | "other";
export interface ShortcutToken {
    label: string; /** Physical key id, matched against held keys. */
    id: string;
    spoken: string;
}
/** The physical key id for an event: "k", "1", "enter", "meta", "shift"… `null` for keys that never make a shortcut, such as Caps Lock. */
export declare function keyId(event: Pick<KeyboardEvent, "key" | "code">): string | null;
/** One spelling per combination: on a Mac, Command is `mod`; elsewhere, Ctrl is. Modifiers come in a fixed order. */
export declare function normalizeShortcut(shortcut: string, platform: Platform): string;
/** Reads a keydown into a shortcut string, or `null` while only modifiers are down. */
export declare function shortcutFromEvent(event: Pick<KeyboardEvent, "key" | "code" | "metaKey" | "ctrlKey" | "altKey" | "shiftKey">, platform: Platform): string | null;
/** True when a keydown is this shortcut. Use it to run the action a recorded shortcut is bound to. */
export declare function matchesShortcut(event: Pick<KeyboardEvent, "key" | "code" | "metaKey" | "ctrlKey" | "altKey" | "shiftKey">, shortcut: string, platform: Platform): boolean;
/** Key caps for a shortcut in platform order: ⌃ ⌥ ⇧ ⌘ on a Mac, Ctrl Alt Shift Win elsewhere. */
export declare function shortcutTokens(shortcut: string, platform: Platform): ShortcutToken[];
/** "⌘⇧K" on a Mac, "Ctrl+Shift+K" elsewhere, plus a spoken form for assistive technology. */
export declare function formatShortcut(shortcut: string, platform: Platform): {
    text: string;
    spoken: string;
};
/** The viewer's platform. Server renders assume a Mac and settle on the first client render. */
export declare function usePlatform(override?: Platform): Platform;
/** Keys held down right now, by physical id. Clears on window blur, and after Command lifts (macOS drops the other keyups). */
export declare function usePressedKeys(enabled?: boolean): ReadonlySet<string>;
export interface ShortcutKbdProps extends HTMLAttributes<HTMLElement> {
    children: ReactNode;
    /** Draws the key pushed down and lit, such as while it is held. */
    pressed?: boolean;
    size?: "sm" | "md";
}
/** A key cap. Use it inline in copy, in menus, or through `ShortcutKeys` for a whole combination. */
export declare const ShortcutKbd: import("react").ForwardRefExoticComponent<ShortcutKbdProps & import("react").RefAttributes<HTMLElement>>;
/** @deprecated Renamed to `ShortcutKbd` so it never clashes with the `kbd` component's `Kbd` in a shared index.ts. */
export declare const Kbd: import("react").ForwardRefExoticComponent<ShortcutKbdProps & import("react").RefAttributes<HTMLElement>>;
/** @deprecated Renamed to `ShortcutKbdProps`. */
export type KbdProps = ShortcutKbdProps;
export interface ShortcutKeysProps {
    shortcut: string;
    platform?: Platform;
    /** Held key ids from `usePressedKeys`; matching caps light up. */
    pressed?: ReadonlySet<string>;
    size?: "sm" | "md";
    className?: string;
}
/** A shortcut as key caps, with a spoken label for assistive technology. */
export declare function ShortcutKeys({ shortcut, platform: platformProp, pressed, size, className }: ShortcutKeysProps): import("react/jsx-runtime").JSX.Element;
export interface ShortcutBinding {
    shortcut: string;
    label: string;
}
export interface ShortcutRecorderProps {
    label: string;
    hideLabel?: boolean;
    value?: string | null;
    defaultValue?: string | null;
    /** `replaced` is the binding the new shortcut was taken from after "Use anyway", so the caller can unbind it. */
    onValueChange?: (value: string | null, details: {
        replaced?: ShortcutBinding;
    }) => void;
    /** What the reset button restores. Defaults to `defaultValue`. */
    resetValue?: string | null;
    /** Shortcuts already in use. Recording one of them asks before taking it. */
    bindings?: ShortcutBinding[];
    /** Also warn about combinations the browser or system keeps, such as ⌘W and ⌘C. On by default. */
    warnReserved?: boolean;
    /** Require ⌘, Ctrl, or ⌥ (function keys are always allowed). On by default. */
    requireModifier?: boolean;
    platform?: Platform;
    placeholder?: string;
    description?: string;
    disabled?: boolean;
    id?: string;
    className?: string;
    text?: RecorderText;
}
export interface RecorderText {
    record: string;
    press: string;
    recording: string;
    cancelled: string;
    cleared: string;
    set: string;
    hint: string;
    includeMac: string;
    includeOther: string;
    reserved: string;
    used: string;
    useAnyway: string;
    cancel: string;
    clear: string;
    reset: string;
    none: string;
}
/**
 * A field that records a keyboard shortcut. Press it, then press the combination: held modifiers light up as caps while you
 * build the chord, the finished shortcut settles into place, and a combination that is already taken asks before it is used.
 * Escape cancels, Backspace clears, and the reset button restores the default. Caps follow the platform: ⌘ on a Mac, Ctrl elsewhere.
 */
export declare function ShortcutRecorder({ label, hideLabel, value, defaultValue, onValueChange, resetValue, bindings, warnReserved, requireModifier, platform: platformProp, placeholder, description, disabled, id, className, text, }: ShortcutRecorderProps): import("react/jsx-runtime").JSX.Element;
export interface ShortcutListItem {
    label: string;
    shortcut: string; /** Extra words that match when searching. */
    keywords?: string;
}
export interface ShortcutListGroup {
    label: string;
    items: ShortcutListItem[];
}
export interface ShortcutListProps {
    groups: ShortcutListGroup[];
    /** Accessible name of the list. */
    label?: string;
    searchable?: boolean;
    searchPlaceholder?: string;
    /** Light up caps as keys are held, and fade rows that do not use the held modifiers. On by default. */
    highlightPressed?: boolean;
    platform?: Platform;
    className?: string;
    emptyLabel?: string;
    matchesLabel?: string;
}
/**
 * A searchable, grouped cheatsheet. Holding a modifier lights that cap in every row and fades rows that do not use it, so people
 * can explore by holding keys; a row whose whole combination is down lights up. Search matches names, groups, and key names such as "cmd k".
 */
export declare function ShortcutList({ groups, label, searchable, searchPlaceholder, highlightPressed, platform: platformProp, className, emptyLabel, matchesLabel }: ShortcutListProps): import("react/jsx-runtime").JSX.Element;
export default ShortcutRecorder;
