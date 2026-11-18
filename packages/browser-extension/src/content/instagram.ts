import type {Asset} from '../core/types';
import {httpUrl, safeName} from '../core/urls';
import {parseSrcset} from './responsive-image';

type RecordValue = Record<string, unknown>;
const record = (value: unknown): RecordValue | undefined =>
  value && typeof value === 'object' && !Array.isArray(value) ? value as RecordValue : undefined;
const integer = (value: unknown) => typeof value === 'number' && Number.isInteger(value) && value > 0 && value <= 16384 ? value : 0;
const postPath = /^\/(?:p|reels?|tv)\/([A-Za-z0-9_-]{5,24})(?:\/embed)?\/?$/;
const maxEmbedBytes = 1024 * 1024;

function isInstagram(url: URL) {
  return ['https://instagram.com', 'https://www.instagram.com'].includes(url.origin);
}

function cdn(value: unknown) {
  const url = typeof value === 'string' ? httpUrl(value) : null;
  return url && /^https:\/\/(?:[a-z\d-]+\.)+(?:cdninstagram\.com|fbcdn\.net)\//i.test(url) ? url : null;
}

function contextData(html: string) {
  const marker = /"contextJSON"\s*:\s*(?=")/.exec(html);
  if (!marker) return undefined;
  const start = marker.index + marker[0].length;
  for (let end = start + 1; end < html.length; end++) {
    if (html[end] === '\\') {
      end++;
    } else if (html[end] === '"') {
      try {
        return record(JSON.parse(JSON.parse(html.slice(start, end + 1))));
      } catch {
        return undefined;
      }
    }
  }
}

function dimensions(node: RecordValue) {
  return {width: integer(node.width), height: integer(node.height)};
}

function renditions(value: unknown, urlKey: string, widthKey: string, heightKey: string) {
  if (!Array.isArray(value)) return [];
  return value.slice(0, 40).flatMap(item => {
    const data = record(item);
    const url = cdn(data?.[urlKey]);
    if (!data || !url) return [];
    return [{url, width: integer(data[widthKey]), height: integer(data[heightKey]), bitrate: integer(data.bitrate_kbps)}];
  });
}

export function instagramEmbedAssets(html: string, page: URL, high: boolean): Asset[] {
  const shortcode = isInstagram(page) ? postPath.exec(page.pathname)?.[1] : undefined;
  if (!shortcode || html.length > maxEmbedBytes) return [];
  const payload = contextData(html);
  if (record(payload?.context)?.copyright_blocked === true) return [];
  const media = record(record(payload?.gql_data)?.shortcode_media);
  if (media) {
    if (media.shortcode !== shortcode) return [];
    const edges = record(media.edge_sidecar_to_children)?.edges;
    const nodes = Array.isArray(edges) ? edges.slice(0, 30).map(edge => record(record(edge)?.node)) : [media];
    return nodes.flatMap((node, index) => {
      if (!node) return [];
      const size = dimensions(record(node.dimensions) ?? {});
      const photo = cdn(node.display_url);
      const sources = renditions(node.display_resources, 'src', 'config_width', 'config_height');
      const sameCrop = sources.filter(item => !size.width || !size.height || !item.width || !item.height
        || Math.abs(item.width * size.height / (item.height * size.width) - 1) < 0.05);
      const candidates = sameCrop.length ? sameCrop : sources;
      const largest = [...candidates].sort((a, b) => b.width * b.height - a.width * a.height)[0];
      const preview = candidates.find(item => item.url === photo);
      const isVideo = node.is_video === true;
      const direct = cdn(node.video_url);
      const videoVersions = renditions(node.video_versions, 'url', 'width', 'height')
        .filter(item => /\.mp4$/i.test(new URL(item.url).pathname))
        .sort((a, b) => b.width * b.height - a.width * a.height || b.bitrate - a.bitrate);
      const bestVideo = high ? videoVersions[0] : undefined;
      const video = direct && /\.mp4$/i.test(new URL(direct).pathname) ? direct : null;
      const url = isVideo ? bestVideo?.url ?? video ?? videoVersions[0]?.url : (high ? largest?.url : photo) ?? photo ?? largest?.url;
      if (!url) return [];

      const sourcePage = new URL(page.href);
      sourcePage.pathname = sourcePage.pathname.replace(/embed\/$/, '');
      sourcePage.search = '';
      if (nodes.length > 1) sourcePage.searchParams.set('img_index', String(index + 1));
      const caption = typeof node.accessibility_caption === 'string' ? node.accessibility_caption : '';
      const name = safeName(caption, `Instagram ${shortcode}${nodes.length > 1 ? ' ' + (index + 1) : ''}`);
      const fallbackUrl = isVideo ? [video, ...videoVersions.map(item => item.url)].find(source => source && source !== url) : photo;
      const measured = isVideo ? bestVideo ?? {width: 0, height: 0} : preview ?? (photo ? size : largest ?? size);
      return [{
        id: url, url, previewUrl: photo ?? largest?.url ?? null, name, kind: isVideo ? 'video' : 'image',
        width: measured.width, height: measured.height, pageUrl: sourcePage.href, description: caption.slice(0, 4000),
        ...(fallbackUrl && fallbackUrl !== url ? {fallbackUrl} : {}),
      }];
    });
  }

  // Standard photo embeds expose their image and srcset directly in markup.
  const image = /<img\b[^>]{0,16384}\bclass=["'][^"']*\bEmbeddedMediaImage\b[^"']*["'][^>]{0,16384}>/i.exec(html)?.[0];
  if (!image || /^\/reels?\//.test(page.pathname)) return [];
  const attr = (name: string) => new RegExp(`\\s${name}="([^"]*)"`, 'i').exec(image)?.[1]?.replace(/&amp;|&#38;/g, '&') ?? '';
  const photo = cdn(attr('src'));
  const candidates = parseSrcset(attr('srcset'), page.href).filter(item => item.unit === 'w' && cdn(item.url));
  const largest = [...candidates].sort((a, b) => b.size - a.size)[0];
  const url = (high ? largest?.url : photo) ?? photo ?? largest?.url;
  if (!url) return [];
  const name = safeName(attr('alt').replace(/&#0*64;/g, '@'), `Instagram ${shortcode}`);
  return [{
    id: url, url, previewUrl: photo ?? url, name, kind: 'image', width: 0, height: 0,
    pageUrl: page.href, description: '', ...(photo && photo !== url ? {fallbackUrl: photo} : {}),
  }];
}

export function instagramPostForVideo(video: HTMLVideoElement): URL | null {
  const page = new URL(location.href);
  if (!isInstagram(page)) return null;
  const read = (link: HTMLAnchorElement | null) => {
    if (!link) return null;
    const url = new URL(link.href);
    return url.origin === page.origin && postPath.test(url.pathname) ? url : null;
  };
  const linked = read(video.closest('a[href]'));
  if (linked && linked.pathname !== page.pathname) return linked;
  if (postPath.test(page.pathname)) return page;
  return linked ?? read(video.closest('article')?.querySelector('a:has(time)') ?? null);
}

const pending = new Map<string, {until: number; result: Promise<Asset[]>}>();
export function instagramPostAssets(page: URL, high: boolean): Promise<Asset[]> {
  if (!isInstagram(page) || !postPath.test(page.pathname)) return Promise.resolve([]);
  const path = page.pathname.replace(/^\/reels\//, '/reel/').replace(/(?:\/embed)?\/?$/, '');
  const address = new URL(path + '/embed/', page.origin);
  const key = address.href + '#' + high;
  const cached = pending.get(key);
  if (cached && cached.until > Date.now()) return cached.result;
  const result = readEmbed(address).then(html => instagramEmbedAssets(html, page, high)).catch(() => []);
  if (pending.size >= 4) pending.delete(pending.keys().next().value!);
  pending.set(key, {until: Date.now() + 45000, result});
  return result;
}

async function readEmbed(address: URL): Promise<string> {
  const response = await fetch(address, {credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(4500)});
  if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) return '';
  const reader = response.body?.getReader();
  if (!reader) return '';
  const decoder = new TextDecoder();
  let html = '';
  let bytes = 0;
  try {
    for (;;) {
      const {done, value} = await reader.read();
      if (done) return html + decoder.decode();
      bytes += value.byteLength;
      if (bytes > maxEmbedBytes) return '';
      html += decoder.decode(value, {stream: true});
    }
  } finally {
    await reader.cancel().catch(() => {});
  }
}

export async function instagramVideo(post: URL, high: boolean): Promise<Asset | null> {
  const assets = await instagramPostAssets(post, high);
  const slide = post.searchParams.get('img_index');
  if (!slide) {
    const standalone = assets.length === 1 && !new URL(assets[0]!.pageUrl).searchParams.has('img_index') ? assets[0] : null;
    return standalone?.kind === 'video' ? standalone : null;
  }
  return assets.find(asset => asset.kind === 'video' && new URL(asset.pageUrl).searchParams.get('img_index') === slide) ?? null;
}
