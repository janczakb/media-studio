import type {Asset, Preferences} from '../core/types';
import {send} from '../core/webextension';
import {instagramPostForVideo, instagramVideo} from './instagram';
import {extractAsset} from './collector';

type Hit = {asset: Asset} | {post: URL; source: string};

const editable = 'input,textarea,select,[contenteditable]:not([contenteditable="false"]),[data-flex-capture]';

export function installContextCapture(preferences: Preferences, signal: AbortSignal) {
  let down: {x: number; y: number} | undefined;
  let target: {hit: Hit; time: number} | undefined;
  let saved = false;
  let feedback: HTMLElement | undefined;
  let feedbackTimer: ReturnType<typeof setTimeout> | undefined;

  function inspect(event: MouseEvent): Hit | undefined {
    if (event.composedPath().some(node => node instanceof Element && node.matches(editable))) return;
    const candidates = new Set<Element>();
    for (const node of event.composedPath()) {
      if (node instanceof Element && node !== document.body && node !== document.documentElement) candidates.add(node);
    }
    for (const node of document.elementsFromPoint(event.clientX, event.clientY).slice(0, 12)) {
      if (node !== document.body && node !== document.documentElement) candidates.add(node);
    }
    for (const node of candidates) {
      const asset = extractAsset(node, preferences.highResolution);
      if (asset) return {asset};
      if (node instanceof HTMLVideoElement) {
        const post = instagramPostForVideo(node);
        if (post) return {post, source: node.currentSrc || node.src};
      }
    }
  }

  function flash(x: number, y: number) {
    feedback?.remove();
    clearTimeout(feedbackTimer);
    const ring = document.createElement('span');
    ring.dataset.flexCapture = 'true';
    ring.setAttribute('aria-hidden', 'true');
    ring.style.cssText = `all:initial;position:fixed;left:${x - 15}px;top:${y - 15}px;width:30px;height:30px;box-sizing:border-box;border:2px solid #3d9cff;border-radius:50%;pointer-events:none;z-index:2147483647`;
    document.documentElement.append(ring);
    feedback = ring;
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
      ring.animate([{transform: 'scale(.45)', opacity: 1}, {transform: 'scale(1.7)', opacity: 0}], {duration: 380, easing: 'ease-out', fill: 'forwards'});
    }
    feedbackTimer = setTimeout(() => ring.remove(), 380);
  }

  const options = {capture: true, signal};
  document.addEventListener('mousedown', event => {
    if (!event.isTrusted || event.button !== 2) return;
    down = {x: event.clientX, y: event.clientY};
    saved = false;
    target = undefined;
  }, options);
  document.addEventListener('contextmenu', event => {
    if (!event.isTrusted) return;
    const hit = inspect(event);
    target = hit ? {hit, time: Date.now()} : undefined;
    if (!hit || !event.altKey || !preferences.altRightClick) return;
    event.preventDefault();
    event.stopPropagation();
    // Chromium can dispatch contextmenu before mouseup. Save once on release.
    if (!down) save(event, hit);
  }, options);
  document.addEventListener('mouseup', event => {
    if (!event.isTrusted || event.button !== 2) return;
    const moved = down && Math.hypot(event.clientX - down.x, event.clientY - down.y) > 5;
    down = undefined;
    if (!moved && event.altKey && preferences.altRightClick) {
      const hit = target?.hit ?? inspect(event);
      if (hit) save(event, hit);
    } else if (moved) {
      saved = true;
    }
  }, options);
  signal.addEventListener('abort', () => {
    feedback?.remove();
    clearTimeout(feedbackTimer);
  }, {once: true});

  function resolve(hit: Hit) {
    return 'asset' in hit ? Promise.resolve(hit.asset) : instagramVideo(hit.post, preferences.highResolution);
  }

  function save(event: MouseEvent, hit: Hit) {
    if (saved) return;
    saved = true;
    void resolve(hit).then(async asset => {
      if (!asset || signal.aborted) return;
      await send('quick.capture', {asset});
      if (!signal.aborted) flash(event.clientX, event.clientY);
    }).catch(() => {});
  }

  return (url: string | undefined) => {
    if (!target || Date.now() - target.time > 30000) return null;
    const {hit} = target;
    const sources = 'asset' in hit ? [hit.asset.url, hit.asset.previewUrl, hit.asset.fallbackUrl] : [hit.source];
    return !url || sources.includes(url) ? resolve(hit) : null;
  };
}
