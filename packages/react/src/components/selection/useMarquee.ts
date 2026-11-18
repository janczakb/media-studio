import {useEffect, useRef, type RefObject} from 'react';
import type {BulkEntry} from '../../contracts';

/** Only mounted cards are measured. Visited geometry is bounded by the bulk limit. */
export function useMarquee({viewport, surface, enabled, selection, onChange, limit = 100}: {
  viewport: RefObject<HTMLDivElement | null>;
  surface?: RefObject<HTMLElement | null>;
  enabled: boolean;
  selection: ReadonlyMap<string, BulkEntry>;
  onChange: (value: ReadonlyMap<string, BulkEntry>) => void;
  limit?: number;
}) {
  const latest = useRef({selection, onChange});
  latest.current = {selection, onChange};
  useEffect(() => {
    const element = viewport.current;
    if (!element || !enabled) return;
    let drag: {id: number; x: number; y: number; clientX: number; clientY: number; active: boolean; additive: boolean; base: ReadonlyMap<string, BulkEntry>; hits: Map<string, {entry: BulkEntry; left: number; right: number; top: number; bottom: number}>} | null = null;
    let frame = 0;
    let overlay: HTMLDivElement | null = null;
    let suppressClick = false;
    let clickTimer: ReturnType<typeof setTimeout> | undefined;
    const reset = () => {
      if (frame) cancelAnimationFrame(frame);
      frame = 0;
      overlay?.remove(); overlay = null;
      element.classList.remove('is-selecting');
      const pointerId = drag?.id;
      drag = null;
      if (pointerId !== undefined && element.hasPointerCapture(pointerId)) element.releasePointerCapture(pointerId);
    };
    const tick = () => {
      frame = 0;
      if (!drag) return;
      const bounds = element.getBoundingClientRect();
      const localX = Math.max(0, Math.min(bounds.width, drag.clientX - bounds.left));
      const localY = Math.max(0, Math.min(bounds.height, drag.clientY - bounds.top));
      const y = element.scrollTop + localY;
      if (!drag.active && Math.hypot(localX - drag.x, y - drag.y) < 5) return;
      drag.active = true;
      element.classList.add('is-selecting');
      if (!overlay) {overlay = document.createElement('div'); overlay.className = 'fms-marquee'; overlay.setAttribute('aria-hidden', 'true'); element.append(overlay);}
      const rect = {left: Math.min(drag.x, localX), right: Math.max(drag.x, localX), top: Math.min(drag.y, y), bottom: Math.max(drag.y, y)};
      Object.assign(overlay.style, {left: rect.left + 'px', top: rect.top + 'px', width: rect.right - rect.left + 'px', height: rect.bottom - rect.top + 'px'});
      for (const card of element.querySelectorAll<HTMLElement>('[data-select-entry]')) {
        const id = card.dataset.selectEntry!, revision = Number(card.dataset.selectRevision);
        if (!Number.isInteger(revision)) continue;
        const box = card.getBoundingClientRect();
        const geometry = {entry: {id, revision}, left: box.left - bounds.left, right: box.right - bounds.left, top: box.top - bounds.top + element.scrollTop, bottom: box.bottom - bounds.top + element.scrollTop};
        const intersects = geometry.left < rect.right && geometry.right > rect.left && geometry.top < rect.bottom && geometry.bottom > rect.top;
        if (intersects && (drag.hits.has(id) || drag.hits.size < limit)) drag.hits.set(id, geometry);
        else drag.hits.delete(id);
      }
      const next = new Map(drag.additive ? drag.base : []);
      for (const [id, box] of drag.hits) {
        if (box.left >= rect.right || box.right <= rect.left || box.top >= rect.bottom || box.bottom <= rect.top) {drag.hits.delete(id); continue;}
        if (next.size < limit) next.set(id, box.entry);
      }
      if (next.size !== latest.current.selection.size || [...next.keys()].some(id => !latest.current.selection.has(id))) latest.current.onChange(next);
      const velocity = localY < 40 ? -(40 - localY) / 3 : localY > bounds.height - 40 ? (localY - bounds.height + 40) / 3 : 0;
      if (velocity) {
        const previous = element.scrollTop;
        element.scrollTop += velocity;
        if (element.scrollTop !== previous) frame = requestAnimationFrame(tick);
      }
    };
    const schedule = () => {if (!frame) frame = requestAnimationFrame(tick);};
    const down = (event: PointerEvent) => {
      if (event.button !== 0 || event.pointerType !== 'mouse' || !(event.target instanceof Element)) return;
      if (surface && !surface.current?.contains(event.target)) return;
      if (event.target.closest('button,input,select,textarea,a,label,[role="link"],[role="button"],[role="checkbox"],[role="combobox"],[contenteditable],.fms-card-checkbox,.fms-photo-checkbox,.fms-marquee')) return;
      const bounds = element.getBoundingClientRect();
      drag = {id: event.pointerId, x: event.clientX - bounds.left, y: event.clientY - bounds.top + element.scrollTop, clientX: event.clientX, clientY: event.clientY, active: false, additive: event.metaKey || event.ctrlKey || event.shiftKey, base: new Map(latest.current.selection), hits: new Map()};
      element.setPointerCapture(event.pointerId);
      event.preventDefault();
    };
    const move = (event: PointerEvent) => {if (!drag || drag.id !== event.pointerId) return; drag.clientX = event.clientX; drag.clientY = event.clientY; schedule();};
    const up = (event: PointerEvent) => {
      if (!drag || drag.id !== event.pointerId) return;
      if (frame) {cancelAnimationFrame(frame); frame = 0; tick();}
      suppressClick = drag.active;
      reset();
      clearTimeout(clickTimer);
      clickTimer = setTimeout(() => {suppressClick = false;}, 0);
    };
    const click = (event: MouseEvent) => {if (suppressClick) {event.preventDefault(); event.stopPropagation(); suppressClick = false;}};
    const cancel = () => {if (drag) {latest.current.onChange(drag.base); reset();}};
    const key = (event: KeyboardEvent) => {if (drag && event.key === 'Escape') {event.preventDefault(); cancel();}};
    element.addEventListener('pointerdown', down);
    element.addEventListener('pointermove', move);
    element.addEventListener('pointerup', up);
    element.addEventListener('pointercancel', cancel);
    element.addEventListener('lostpointercapture', cancel);
    element.addEventListener('scroll', schedule, {passive: true});
    element.addEventListener('click', click, true);
    element.ownerDocument.addEventListener('keydown', key);
    return () => {
      // Remove cancellation listeners before releasing capture at teardown.
      element.removeEventListener('lostpointercapture', cancel);
      reset(); clearTimeout(clickTimer);
      element.removeEventListener('pointerdown', down); element.removeEventListener('pointermove', move); element.removeEventListener('pointerup', up);
      element.removeEventListener('pointercancel', cancel); element.removeEventListener('scroll', schedule); element.removeEventListener('click', click, true);
      element.ownerDocument.removeEventListener('keydown', key);
    };
  }, [viewport, surface, enabled, limit]);
}
