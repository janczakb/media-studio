import type { Messages } from '../ui';
import { type ShortcutBindings, type ShortcutCommand, type ShortcutRegistry } from './registry';
export interface ShortcutSettingsProps {
    registry: ShortcutRegistry | null;
    bindings: Record<ShortcutCommand, string | null>;
    defaults?: ShortcutBindings;
    overrides: ShortcutBindings;
    customizable: boolean;
    onChange: (bindings: ShortcutBindings) => void;
    t: Messages;
}
export declare function ShortcutSettings({ registry, bindings, defaults, overrides, customizable, onChange, t }: ShortcutSettingsProps): import("react/jsx-runtime").JSX.Element;
