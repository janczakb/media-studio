import {httpUrl} from '../core/urls';

type Candidate = {url: string; size: number; unit: 'w' | 'x'};

export function parseSrcset(input: string, base: string): Candidate[] {
  const value = input.slice(0, 65536);
  const candidates: Candidate[] = [];
  const token = /[^\t\n\f\r ]+/y;
  const separator = /[,\t\n\f\r ]*/y;
  const descriptor = /[^,]*/y;
  let cursor = 0;
  while (cursor < value.length && candidates.length < 100) {
    separator.lastIndex = cursor;
    separator.exec(value);
    token.lastIndex = separator.lastIndex;
    const match = token.exec(value);
    if (!match) break;

    const rawUrl = match[0];
    cursor = token.lastIndex;
    let spec = '';
    if (!rawUrl.endsWith(',')) {
      descriptor.lastIndex = cursor;
      spec = descriptor.exec(value)?.[0].trim() ?? '';
      cursor = descriptor.lastIndex + 1;
    }
    const url = httpUrl(rawUrl.replace(/,+$/, ''), base);
    if (!url) continue;
    if (!spec) {
      candidates.push({url, size: 1, unit: 'x'});
      continue;
    }
    const width = /^(\d+)w$/.exec(spec);
    const density = /^((?:\d+(?:\.\d+)?|\.\d+)(?:e[+-]?\d+)?)x$/i.exec(spec);
    const size = Number(width?.[1] ?? density?.[1]);
    if (Number.isFinite(size) && size > 0) candidates.push({url, size, unit: width ? 'w' : 'x'});
  }
  return candidates;
}

export function largestResponsiveImage(image: HTMLImageElement, preview: string | null): string | null {
  const base = image.baseURI;
  const candidatesFor = (node: Element) => parseSrcset(node.getAttribute('srcset') || node.getAttribute('data-srcset') || '', base);
  const own = candidatesFor(image);
  const sets: {media: string; candidates: Candidate[]}[] = [];
  if (image.parentElement?.tagName === 'PICTURE') {
    for (let node = image.previousElementSibling; node; node = node.previousElementSibling) {
      if (node.tagName !== 'SOURCE') continue;
      const media = node.getAttribute('media')?.trim() ?? '';
      if (media && !matchMedia(media).matches) continue;
      const candidates = candidatesFor(node);
      if (candidates.length) sets.unshift({media, candidates});
    }
  }
  // A media-specific source can depict a different crop. Keep the active crop.
  const selected = sets.find(set => set.candidates.some(candidate => candidate.url === preview));
  const active = selected ?? (preview ? undefined : sets[0]);
  const candidates = active
    ? sets.filter(set => set.media === active.media).flatMap(set => set.candidates)
    : own;
  if (active && !active.media) candidates.push(...own);
  if (!candidates.length) return null;

  const withWidth = candidates.some(candidate => candidate.unit === 'w');
  const withDensity = candidates.some(candidate => candidate.unit === 'x');
  const visible = candidates.find(candidate => candidate.url === preview);
  const slot = withWidth && withDensity ? (visible?.unit === 'x' && image.naturalWidth || image.clientWidth) : 0;
  // Compare pixel widths when the slot is known. Otherwise retain the units of
  // the chosen source, which are comparable within that source set.
  const unit = visible?.unit ?? candidates[0]?.unit;
  let best: Candidate | undefined;
  let largest = 0;
  for (const candidate of candidates) {
    if (!slot && candidate.unit !== unit) continue;
    const pixels = candidate.size * (candidate.unit === 'x' && slot ? slot : 1);
    if (pixels > largest) {
      largest = pixels;
      best = candidate;
    }
  }
  return best?.url ?? null;
}
