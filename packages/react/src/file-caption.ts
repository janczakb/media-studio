import type {Entry} from './contracts';

/** Decimal display units; exact byte counts remain available on the resource. */
export function formatFileBytes(bytes: number | null, locale?: string): string {
  if (bytes === null || !Number.isFinite(bytes) || bytes < 0) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  let index = Math.min(bytes > 0 ? Math.floor(Math.log10(bytes) / 3) : 0, units.length - 1);
  let value = bytes / 1000 ** index;
  if (index < units.length - 1 && Math.round(value * 10) / 10 >= 1000) {
    index++;
    value = bytes / 1000 ** index;
  }
  return `${new Intl.NumberFormat(locale, {maximumFractionDigits: index === 0 ? 0 : 1}).format(value)} ${units[index]}`;
}

export function fileNameParts(entry: Pick<Entry, 'name' | 'kind' | 'mediaType'>) {
  const suffix = entry.kind === 'file' && entry.mediaType !== 'note'
    ? entry.name.match(/\.[a-z0-9]{1,12}$/i)?.[0] ?? ''
    : '';
  return suffix && suffix.length < entry.name.length
    ? {title: entry.name.slice(0, -suffix.length), suffix}
    : {title: entry.name, suffix: ''};
}

export function fileCaption(entry: Pick<Entry, 'name'|'mimeType'|'size'|'width'|'height'>, locale?: string): string {
  const extension = entry.name.match(/\.([a-z0-9]{1,12})$/i)?.[1];
  const subtype = entry.mimeType?.split('/')[1]?.split('+')[0];
  const type = extension?.toUpperCase() ?? subtype?.toUpperCase();
  const dimensions = entry.width && entry.height && entry.width > 0 && entry.height > 0
    ? `${Math.round(entry.width)} × ${Math.round(entry.height)}` : null;
  return [type, dimensions, entry.size === null ? null : formatFileBytes(entry.size, locale)].filter(Boolean).join(' · ');
}
