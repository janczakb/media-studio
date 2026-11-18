export interface ScrollAnchor {key: string; offset: number}
/** Capture only rendered rows; never scan the backing data or the document. */
export function captureScrollAnchor(viewport: HTMLElement | null, selector: string, attribute: string): ScrollAnchor | null {
  if (!viewport || viewport.scrollTop <= 0) return null;
  const top = viewport.getBoundingClientRect().top + viewport.clientTop;
  for (const element of viewport.querySelectorAll<HTMLElement>(selector)) {
    const rect = element.getBoundingClientRect();
    const key = element.getAttribute(attribute);
    if (key && rect.bottom > top) return {key, offset: rect.top - top};
  }
  return null;
}
export function restoreScrollAnchor(viewport: HTMLElement | null, keys: readonly string[], pitch: number, anchor: ScrollAnchor | null) {
  if (!viewport || !anchor) return;
  const index = keys.indexOf(anchor.key);
  if (index >= 0) viewport.scrollTop = Math.max(0, index * pitch - anchor.offset);
}
