import {I18nProvider} from '@heroui/react';
import {createContext, useContext, useMemo, type ReactNode} from 'react';
import type {Messages} from './ui';
import {studioLocales, studioMessageKeys, workspaceMessageKeys, namespaceKeys} from './studio-locales';

export type StudioNamespaces = {
  management: Record<keyof typeof import('./components/management/locales/en').default, string>;
  ai: Record<keyof typeof import('./components/ai/locales/en').default, string>;
  integrations: Record<keyof typeof import('./components/integrations/locales/en').default, string>;
};
export type WorkspaceMessages = Record<(typeof workspaceMessageKeys)[number], string>;
export interface StudioLocaleCatalog {
  label?: string;
  messages?: Partial<Messages>;
  workspace?: Partial<WorkspaceMessages>;
  namespaces?: {[K in keyof StudioNamespaces]?: Partial<StudioNamespaces[K]>};
  load?: () => Promise<Omit<StudioLocaleCatalog, 'load'>>;
}
export interface StudioLocalization {
  catalogs?: Record<string, StudioLocaleCatalog>;
  bootstrap?: {loading?: string; retry?: string};
}
export interface LoadedStudioLocale {
  locale: string;
  fallbackLocale: string;
  messages: Messages;
  workspace: WorkspaceMessages;
  namespaces: StudioNamespaces;
}
export interface LocalizationState extends LoadedStudioLocale {
  pending: boolean;
  requestLocale: (locale: string) => void;
}

export const defaultLocale = 'en';
const builtins = new Set<string>(studioLocales.map(value => value.id));
const files = new Map<string, Promise<StudioLocaleCatalog>>();
const retries = new Map<string, number>();
const external = new WeakMap<NonNullable<StudioLocaleCatalog['load']>, Promise<StudioLocaleCatalog>>();

export function canonicalLocale(value: unknown): string | undefined {
  if (typeof value !== 'string' || !value.trim()) return;
  try { return Intl.getCanonicalLocales(value.replaceAll('_', '-'))[0]; }
  catch { return; }
}

/** Exact region, language/script, then base language. Never build paths from raw input. */
export function localeCandidates(value: string): string[] {
  const locale = canonicalLocale(value);
  if (!locale) return [];
  const parsed = new Intl.Locale(locale);
  const script = parsed.maximize().script;
  return [...new Set([
    locale.toLowerCase(),
    ...(script ? [`${parsed.language}-${script}`.toLowerCase()] : []),
    parsed.language.toLowerCase(),
  ])];
}

export function resolveStudioLocale(...values: unknown[]): string {
  for (const value of values) {
    const locale = canonicalLocale(value);
    if (locale) return locale;
  }
  return defaultLocale;
}

function nativeCatalog(id: string): Promise<StudioLocaleCatalog> {
  let pending = files.get(id);
  if (!pending) {
    const retry = retries.has(id) ? `?retry=${retries.get(id)}` : '';
    pending = import(new URL(`./studio-locale-${id}.js${retry}`, import.meta.url).href)
      .then(module => ({
        messages: module.messages,
        workspace: module.workspace,
        namespaces: {management: module.management, ai: module.ai, integrations: module.integrations},
      }));
    files.set(id, pending);
    void pending.catch(() => {
      files.delete(id);
      retries.set(id, (retries.get(id) ?? 0) + 1);
    });
  }
  return pending;
}

function complete(value: StudioLocaleCatalog): boolean {
  return studioMessageKeys.every(key => typeof value.messages?.[key] === 'string')
    && workspaceMessageKeys.every(key => typeof value.workspace?.[key] === 'string')
    && Object.entries(namespaceKeys).every(([name, keys]) => keys.every(key =>
      typeof (value.namespaces?.[name as keyof StudioNamespaces] as Record<string, unknown> | undefined)?.[key] === 'string'));
}

/** Runtime-invalid overrides cannot replace valid fallback strings. */
function strings<T extends object>(value: T | undefined): T {
  return Object.fromEntries(Object.entries(value ?? {}).filter(([, text]) => typeof text === 'string')) as T;
}

function merge(base: StudioLocaleCatalog, value: StudioLocaleCatalog): StudioLocaleCatalog {
  return {
    ...base, ...value,
    messages: {...strings(base.messages), ...strings(value.messages)},
    workspace: {...strings(base.workspace), ...strings(value.workspace)},
    namespaces: {
      management: {...strings(base.namespaces?.management), ...strings(value.namespaces?.management)},
      ai: {...strings(base.namespaces?.ai), ...strings(value.namespaces?.ai)},
      integrations: {...strings(base.namespaces?.integrations), ...strings(value.namespaces?.integrations)},
    },
  };
}

export async function loadStudioLocale(locale: string, options?: StudioLocalization): Promise<LoadedStudioLocale> {
  const configured = Object.fromEntries(Object.entries(options?.catalogs ?? {})
    .map(([key, value]) => [(canonicalLocale(key) ?? key).toLowerCase(), value]));
  let result: StudioLocaleCatalog = {};
  const applied = new Set<string>();

  for (const desired of [...new Set([locale, defaultLocale])]) {
    let layer: StudioLocaleCatalog = {};
    for (const id of localeCandidates(desired).reverse()) {
      if (applied.has(id)) continue;
      applied.add(id);
      if (builtins.has(id)) {
        try { layer = merge(layer, await nativeCatalog(id)); }
        catch (error) { if (id === defaultLocale) throw error; }
      }
      const config = configured[id];
      if (config?.load) {
        const loader = config.load;
        let pending = external.get(loader);
        if (!pending) {
          pending = Promise.resolve().then(loader);
          external.set(loader, pending);
          void pending.catch(() => external.delete(loader));
        }
        try { layer = merge(layer, await pending); }
        catch { /* A failed optional provider falls back to the shipped EN catalog. */ }
      }
      if (config) layer = merge(layer, config);
    }
    result = merge(layer, result);
    if (complete(result)) {
      return {
        locale, fallbackLocale: defaultLocale,
        messages: result.messages as Messages,
        workspace: result.workspace as WorkspaceMessages,
        namespaces: result.namespaces as StudioNamespaces,
      };
    }
  }
  throw new Error('The locale catalog does not satisfy the Studio message schema.');
}

export function studioLanguageOptions(locale: string, options?: StudioLocalization) {
  const entries = new Map<string, string>(studioLocales.map(value => [value.id, value.label]));
  for (const [key, value] of Object.entries(options?.catalogs ?? {})) {
    const id = canonicalLocale(key);
    if (id) entries.set(id, value.label ?? new Intl.DisplayNames([id], {type: 'language'}).of(id) ?? id);
  }
  if (!entries.has(locale)) entries.set(locale, new Intl.DisplayNames([locale], {type: 'language'}).of(locale) ?? locale);
  return [...entries].map(([id, label]) => ({id, label}));
}

const LocaleContext = createContext<LocalizationState | null>(null);
export function StudioLocalizationProvider({value, children}: {value: LocalizationState; children: ReactNode}) {
  return <I18nProvider locale={value.locale}><LocaleContext value={value}>{children}</LocaleContext></I18nProvider>;
}
export function useStudioLocalization(): LocalizationState {
  const value = useContext(LocaleContext);
  if (!value) throw new Error('Studio localization must be loaded before rendering its UI.');
  return value;
}
export function useStudioNamespace<K extends keyof StudioNamespaces>(name: K): StudioNamespaces[K] {
  return useStudioLocalization().namespaces[name];
}
export function useWorkspaceMessages() {
  const {locale, fallbackLocale, workspace: messages} = useStudioLocalization();
  return useMemo(() => ({
    locale, fallbackLocale, messages,
    tr: (key: keyof WorkspaceMessages, values?: Record<string, string | number>) =>
      messages[key].replace(/\{(\w+)\}/g, (match, name: string) => {
        const value = values?.[name];
        return value === undefined ? match : typeof value === 'number' ? new Intl.NumberFormat(locale).format(value) : value;
      }),
  }), [locale, fallbackLocale, messages]);
}
