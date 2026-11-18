export interface CaptureFolder {id: string; name: string;}
export interface FolderPage {items: CaptureFolder[]; nextCursor: string | null; previousCursor?: string | null;}
export type FolderDirection = 'next' | 'previous';
export const folderRowHeight = 36;
export const folderWindowPages = 6;

/** Retain a bounded, bidirectional cursor window and the scroll-anchor correction. */
export function mergeFolderPage(pages: FolderPage[], incoming: FolderPage, direction: FolderDirection): {pages: FolderPage[]; scrollDelta: number} {
  const existing = new Set(pages.flatMap(page => page.items.map(item => item.id)));
  const unique = new Map(incoming.items.slice(0, 60).filter(item => !existing.has(item.id)).map(item => [item.id, item]));
  const items = [...unique.values()];
  if (!items.length) {
    return {pages: pages.map((page, index) => direction === 'next' && index === pages.length - 1 ? {...page, nextCursor: null}
      : direction === 'previous' && index === 0 ? {...page, previousCursor: null} : page), scrollDelta: 0};
  }
  const next = direction === 'next' ? [...pages, {...incoming, items}] : [{...incoming, items}, ...pages];
  let scrollDelta = direction === 'previous' ? items.length * folderRowHeight : 0;
  while (next.length > folderWindowPages) {
    if (direction === 'next') scrollDelta -= (next.shift()?.items.length ?? 0) * folderRowHeight;
    else next.pop();
  }
  return {pages: next, scrollDelta};
}
