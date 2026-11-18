import {useEffect, useRef, useState, type RefObject} from 'react';
import type {BulkEntry, Entry} from '../../contracts';

type Options = {
  viewport: RefObject<HTMLDivElement | null>;
  dropBar: RefObject<HTMLDivElement | null>;
  portal: HTMLDivElement;
  enabled: boolean;
  identity: string;
  workspace: string;
  items: readonly Entry[];
  selection: ReadonlyMap<string, BulkEntry>;
  onChoose: (items: BulkEntry[]) => void;
  onMove: (items: BulkEntry[], parent: string) => void;
};

/** One gesture owner for mounted cards; movement and edge scrolling stay outside React. */
export function useMoveDrag(options: Options) {
  const latest = useRef(options);
  latest.current = options;
  const [count, setCount] = useState(0);

  useEffect(() => {
    const {viewport, portal, enabled} = options;
    const element = viewport.current;
    if (!element || !enabled) return;
    const document = element.ownerDocument;
    const root = element.getRootNode() as ShadowRoot | Document;
    let gesture: {pointer: number; x: number; y: number; clientX: number; clientY: number; items: BulkEntry[]; source: HTMLElement; active: boolean} | null = null;
    let ghost: HTMLDivElement | null = null;
    let target: HTMLElement | null = null;
    let frame = 0;
    let expandTimer: ReturnType<typeof setTimeout> | undefined;
    let suppressClick = false;
    let clickTimer: ReturnType<typeof setTimeout> | undefined;

    const reset = () => {
      clearTimeout(expandTimer);
      cancelAnimationFrame(frame);
      frame = 0;
      ghost?.remove();
      ghost = null;
      target?.removeAttribute('data-move-over');
      target = null;
      for (const card of element.querySelectorAll('[data-moving]')) card.removeAttribute('data-moving');
      element.classList.remove('is-moving-files');
      const pointer = gesture?.pointer;
      gesture = null;
      if (pointer !== undefined && element.hasPointerCapture(pointer)) element.releasePointerCapture(pointer);
      setCount(0);
    };
    const hitTarget = () => {
      if (!gesture) return null;
      const hit = root.elementFromPoint(gesture.clientX, gesture.clientY);
      const bar = latest.current.dropBar.current;
      if (bar && hit && bar.contains(hit)) return bar;
      const folder = hit?.closest<HTMLElement>('[data-move-folder]');
      const sidebarFolder=folder?.dataset.folderWorkspace===latest.current.workspace;
      if (!folder || !(element.contains(folder)||sidebarFolder&&element.closest('.fms-shell')?.contains(folder)) || gesture.items.some(item => item.id === folder.dataset.moveFolder)) return null;
      return folder;
    };
    const tick = () => {
      frame = 0;
      if (!gesture?.active || !ghost) return;
      const bounds = portal.getBoundingClientRect();
      ghost.style.transform = `translate(${gesture.clientX - bounds.left + 12}px,${gesture.clientY - bounds.top + 12}px)`;
      const next = hitTarget();
      if (next !== target) {
        target?.removeAttribute('data-move-over');
        target = next;
        target?.setAttribute('data-move-over', 'true');
        clearTimeout(expandTimer);
        const hovered=target;
        if(hovered?.dataset.folderWorkspace===latest.current.workspace){
          expandTimer=setTimeout(()=>{
            if(target===hovered&&gesture?.active)hovered.querySelector<HTMLButtonElement>('.fms-folder-disclosure[aria-expanded="false"]')?.click();
          },650);
        }
      }
      if (target === latest.current.dropBar.current) return;
      const hit=root.elementFromPoint(gesture.clientX,gesture.clientY);
      const viewport=hit?.closest<HTMLElement>('.fms-sidebar-folder-tree')??element;
      const viewportBounds = viewport.getBoundingClientRect();
      if (gesture.clientX < viewportBounds.left || gesture.clientX > viewportBounds.right
          || gesture.clientY < viewportBounds.top || gesture.clientY > viewportBounds.bottom) return;
      const localY = gesture.clientY - viewportBounds.top;
      const velocity = localY < 48 ? -Math.min(18, (48 - localY) / 3)
        : localY > viewportBounds.height - 48 ? Math.min(18, (localY - viewportBounds.height + 48) / 3) : 0;
      if (velocity) {
        const previous = viewport.scrollTop;
        viewport.scrollTop += velocity;
        if (previous !== viewport.scrollTop) frame = requestAnimationFrame(tick);
      }
    };
    const schedule = () => {if (!frame) frame = requestAnimationFrame(tick);};
    const start = () => {
      if (!gesture) return;
      gesture.active = true;
      setCount(gesture.items.length);
      element.classList.add('is-moving-files');
      const ids = new Set(gesture.items.map(item => item.id));
      for (const card of element.querySelectorAll<HTMLElement>('[data-move-entry]')) {
        if (ids.has(card.dataset.moveEntry!)) card.setAttribute('data-moving', 'true');
      }
      ghost = document.createElement('div');
      ghost.className = 'fms-move-ghost';
      ghost.setAttribute('aria-hidden', 'true');
      const artwork = gesture.source.querySelector('.fms-card-art img,.fms-photo-card img,.fms-file-type-icon,.fms-folder-artwork,.fms-audio-artwork');
      if (artwork) ghost.append(artwork.cloneNode(true));
      const badge = document.createElement('span');
      badge.textContent = String(gesture.items.length);
      ghost.append(badge);
      portal.append(ghost);
      element.setPointerCapture(gesture.pointer);
    };
    const down = (event: PointerEvent) => {
      if (event.button !== 0 || event.pointerType !== 'mouse' || !(event.target instanceof Element)) return;
      if (!event.target.closest('.fms-card,.fms-photo-card')
          || event.target.closest('input,textarea,label,[role="checkbox"],.fms-card-actions,.fms-photo-actions')) return;
      const source = event.target.closest<HTMLElement>('[data-move-entry]');
      const entry = latest.current.items.find(item => item.id === source?.dataset.moveEntry);
      if (!source || !entry || entry.readOnly || entry.trashedAt) return;
      const selected = latest.current.selection;
      const items = selected.has(entry.id) ? [...selected.values()] : [{id: entry.id, revision: entry.revision}];
      if (!items.length) return;
      gesture = {pointer: event.pointerId, x: event.clientX, y: event.clientY, clientX: event.clientX, clientY: event.clientY, items, source, active: false};
    };
    const move = (event: PointerEvent) => {
      if (!gesture || event.pointerId !== gesture.pointer) return;
      gesture.clientX = event.clientX;
      gesture.clientY = event.clientY;
      if (!gesture.active && Math.hypot(event.clientX - gesture.x, event.clientY - gesture.y) < 6) return;
      if (!gesture.active) start();
      event.preventDefault();
      schedule();
    };
    const suppressNextClick = () => {
      suppressClick = true;
      clearTimeout(clickTimer);
      clickTimer = setTimeout(() => {suppressClick = false;}, 100);
    };
    const up = (event: PointerEvent) => {
      if (!gesture || event.pointerId !== gesture.pointer) return;
      if (!gesture.active) {reset(); return;}
      gesture.clientX = event.clientX;
      gesture.clientY = event.clientY;
      const destination = hitTarget();
      const items = gesture.items;
      const folder = destination?.dataset.moveFolder;
      const choose = destination === latest.current.dropBar.current;
      event.preventDefault();
      event.stopImmediatePropagation();
      suppressNextClick();
      reset();
      if (folder) latest.current.onMove(items, folder);
      else if (choose) latest.current.onChoose(items);
    };
    const cancel = () => {
      if (gesture?.active) suppressNextClick();
      reset();
    };
    const key = (event: KeyboardEvent) => {
      if (gesture && event.key === 'Escape') {event.preventDefault();event.stopPropagation();cancel();}
    };
    const click = (event: MouseEvent) => {
      if (suppressClick) {event.preventDefault();event.stopImmediatePropagation();}
    };
    const nativeDrag = (event: DragEvent) => {
      if (gesture) event.preventDefault();
    };
    element.addEventListener('pointerdown', down);
    element.addEventListener('dragstart', nativeDrag);
    document.addEventListener('pointermove', move, {capture: true, passive: false});
    document.addEventListener('pointerup', up, true);
    document.addEventListener('pointercancel', cancel, true);
    document.addEventListener('keydown', key, true);
    element.addEventListener('click', click, true);
    document.defaultView?.addEventListener('blur', cancel);
    return () => {
      reset();
      clearTimeout(clickTimer);
      element.removeEventListener('pointerdown', down);
      element.removeEventListener('dragstart', nativeDrag);
      document.removeEventListener('pointermove', move, true);
      document.removeEventListener('pointerup', up, true);
      document.removeEventListener('pointercancel', cancel, true);
      document.removeEventListener('keydown', key, true);
      element.removeEventListener('click', click, true);
      document.defaultView?.removeEventListener('blur', cancel);
    };
  }, [options.viewport, options.portal, options.enabled, options.identity]);

  return count;
}
