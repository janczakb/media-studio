import {httpUrl} from '../core/urls';
import {pinterestOriginal} from '../core/pinterest-image';

interface Metadata {
  url?: string;
  name?: string;
  description?: string;
  width?: number;
  height?: number;
}

export function siteMetadata(element: Element, page: URL): Metadata {
  const host = page.hostname;
  const link = element.closest('a[href]') as HTMLAnchorElement | null;
  if (host === 'www.bing.com' || host === 'cn.bing.com') {
    const record = element.closest('[data-m],a[m]');
    const raw = record?.getAttribute('data-m') ?? record?.getAttribute('m');
    if (raw && raw.length < 16384) {
      try {
        const data: unknown = JSON.parse(raw);
        if (data && typeof data === 'object' && 'murl' in data && typeof data.murl === 'string') {
          const url = httpUrl(data.murl);
          if (url) return {url, name: 't' in data && typeof data.t === 'string' ? data.t : undefined};
        }
      } catch { /* Ignore malformed page metadata. */ }
    }
  }

  if (/^(?:www\.)?google\.[a-z.]+$/.test(host) && link) {
    const result = new URL(link.href);
    if (result.origin === page.origin && result.pathname === '/imgres') {
      const url = httpUrl(result.searchParams.get('imgurl') ?? '');
      if (url) return {url};
    }
  }

  if (/(^|\.)pinterest\.[a-z.]+$/.test(host)) {
    const card = element.closest('[data-grid-item],[data-test-id="closeup-lego-container"]');
    const title = card?.querySelector('[data-test-id="closeup-title"],[data-test-id="pinrep-footer"]')?.textContent;
    if (title?.trim()) return {name: title.trim()};
  }

  if (['midjourney.com', 'www.midjourney.com', 'nijijourney.com', 'www.nijijourney.com'].includes(host)) {
    const prompt = element.closest('[role="dialog"]')?.querySelector('#lightboxPrompt')?.textContent?.trim();
    if (prompt) return {name: prompt.slice(0, 72), description: prompt.slice(0, 4000)};
  }
  return {};
}

export function largerImage(source: string): string {
  const url = new URL(source);
  if (url.protocol !== 'https:') return source;
  if (url.hostname === 'i.pinimg.com') return pinterestOriginal(source) ?? source;
  if (url.hostname === 'pbs.twimg.com' && url.pathname.startsWith('/media/') && url.searchParams.has('name')) {
    url.searchParams.set('name', 'orig');
  } else if (url.hostname === 'preview.redd.it' && /^\/[\w-]+\.(?:jpe?g|png|gif|webp)$/i.test(url.pathname)) {
    url.hostname = 'i.redd.it';
    url.search = '';
  } else if (url.hostname === 'cdn.bsky.app' && url.pathname.startsWith('/img/feed_thumbnail/')) {
    url.pathname = url.pathname.replace('/img/feed_thumbnail/', '/img/feed_fullsize/');
  }
  return url.href;
}
