import type { EntryPage } from './contracts';

export function appendPage(pages: readonly EntryPage[], incoming: EntryPage, direction: 'next' | 'previous', maximum = 6): EntryPage[] {
  if (!Number.isInteger(maximum) || maximum < 2) throw new Error('A listing window requires at least two pages.');
  const next = direction === 'next' ? [...pages, incoming] : [incoming, ...pages];
  return direction === 'next' ? next.slice(-maximum) : next.slice(0, maximum);
}
