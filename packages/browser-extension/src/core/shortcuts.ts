export const shortcutActions = ['bookmark', 'collect', 'area', 'visible', 'full'] as const;
export type ShortcutAction = typeof shortcutActions[number];
export type Shortcuts = Record<ShortcutAction, string>;

export const defaultShortcuts: Shortcuts = {
  bookmark: 'Alt + 0',
  collect: 'Alt + 1',
  area: 'Alt + 2',
  visible: 'Alt + 3',
  full: 'Alt + 4',
};

export function shortcutFromEvent(event: KeyboardEvent): string {
  if (!event.altKey && !event.ctrlKey && !event.metaKey) return '';
  const key = /^(?:Key|Digit)([A-Z0-9])$/.exec(event.code)?.[1];
  if (!key) return '';
  return [event.ctrlKey && 'Ctrl', event.altKey && 'Alt', event.shiftKey && 'Shift', event.metaKey && 'Meta', key].filter(Boolean).join(' + ');
}

export function normalizeShortcuts(value: unknown): Shortcuts {
  if (!value || typeof value !== 'object') return defaultShortcuts;
  const result = {...defaultShortcuts};
  const fields = value as Partial<Shortcuts>;
  for (const key of shortcutActions) {
    const binding = fields[key];
    if (typeof binding === 'string' && (binding === '' || /^(?:Ctrl \+ )?(?:Alt \+ )?(?:Shift \+ )?(?:Meta \+ )?[A-Z0-9]$/.test(binding) && /(?:Ctrl|Alt|Meta) \+ /.test(binding))) result[key] = binding;
  }
  const active = Object.values(result).filter(Boolean);
  if (new Set(active).size !== active.length) throw new Error('Each action needs a different shortcut.');
  return result;
}
