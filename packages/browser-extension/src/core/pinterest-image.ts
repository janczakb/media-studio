import {httpUrl} from './urls';

const renditions = /^(?:originals|\d{1,4}x\d{0,4})$/;
const modifiers = new Set(['enabled', 'enabled_lo', 'enabled_hi', 'control', 'vwebp']);
const imagePath = /^(?<id>[a-f\d]{2}\/[a-f\d]{2}\/[a-f\d]{2}\/[a-f\d]{32})\.(?<format>jpe?g|png|webp|gif|avif)$/i;

function original(source: string) {
  const value = httpUrl(source);
  if (!value) return null;
  const url = new URL(value);
  if (url.origin !== 'https://i.pinimg.com') return null;
  const [rendition, ...parts] = url.pathname.slice(1).split('/').filter(part => !modifiers.has(part));
  if (!rendition || !renditions.test(rendition)) return null;
  const match = imagePath.exec(parts.join('/'));
  if (!match?.groups) return null;
  return {url, id: match.groups.id!, format: match.groups.format!};
}

export function pinterestOriginal(source: string): string | null {
  const image = original(source);
  if (!image) return null;
  image.url.pathname = `/originals/${image.id}.${image.format}`;
  image.url.search = '';
  return image.url.href;
}

export function pinterestOriginalAlternatives(source: string): string[] {
  const image = original(source);
  if (!image || image.url.pathname !== `/originals/${image.id}.${image.format}`) return [];
  const formats = ['jpg', 'png', 'webp', 'gif'];
  return formats.filter(format => format !== image.format.toLowerCase()).map(format =>
    `https://i.pinimg.com/originals/${image.id}.${format}`);
}

export async function pinterestImageMime(blob: Blob): Promise<string | null> {
  const bytes = new Uint8Array(await blob.slice(0, 32).arrayBuffer());
  const ascii = (start: number, end: number) => String.fromCharCode(...bytes.subarray(start, end));
  if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'image/jpeg';
  if (bytes[0] === 0x89 && ascii(1, 8) === 'PNG\r\n\x1a\n') return 'image/png';
  if (/^GIF8[79]a$/.test(ascii(0, 6))) return 'image/gif';
  if (ascii(0, 4) === 'RIFF' && ascii(8, 12) === 'WEBP') return 'image/webp';
  if (ascii(4, 8) === 'ftyp' && ['avif', 'avis'].includes(ascii(8, 12))) return 'image/avif';
  return null;
}
