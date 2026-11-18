import {open} from 'node:fs/promises';

function boxes(bytes, start, end) {
  const result = [];
  while (start + 8 <= end) {
    let length = bytes.readUInt32BE(start);
    const name = bytes.toString('ascii', start + 4, start + 8);
    let header = 8;
    if (length === 1 && start + 16 <= end) {
      const extended = bytes.readBigUInt64BE(start + 8);
      if (extended > BigInt(end - start)) break;
      length = Number(extended);
      header = 16;
    }
    if (length === 0) length = end - start;
    if (length < header || length > end - start) break;
    result.push({name, start: start + header, end: start + length});
    start += length;
  }
  return result;
}

function avifSize(bytes) {
  const meta = boxes(bytes, 0, bytes.length).find(box => box.name === 'meta');
  if (!meta) return null;
  const metadata = boxes(bytes, meta.start + 4, meta.end);
  const item = metadata.find(box => box.name === 'pitm');
  const properties = metadata.find(box => box.name === 'iprp');
  if (!item || !properties || item.end - item.start < 6) return null;
  if (bytes[item.start] !== 0 && item.end - item.start < 8) return null;
  const primary = bytes[item.start] === 0 ? bytes.readUInt16BE(item.start + 4) : bytes.readUInt32BE(item.start + 4);
  const propertyBoxes = boxes(bytes, properties.start, properties.end);
  const container = propertyBoxes.find(box => box.name === 'ipco');
  if (!container) return null;
  const definitions = boxes(bytes, container.start, container.end);
  for (const map of propertyBoxes.filter(box => box.name === 'ipma')) {
    if (map.end - map.start < 8) continue;
    const wideId = bytes[map.start] > 0;
    const wideIndex = (bytes[map.start + 3] & 1) !== 0;
    let cursor = map.start + 8;
    const entries = bytes.readUInt32BE(map.start + 4);
    for (let index = 0; index < entries && cursor + (wideId ? 5 : 3) <= map.end; index++) {
      const id = wideId ? bytes.readUInt32BE(cursor) : bytes.readUInt16BE(cursor);
      cursor += wideId ? 4 : 2;
      const count = bytes[cursor++];
      if (cursor + count * (wideIndex ? 2 : 1) > map.end) break;
      let size = null;
      let rotate = false;
      for (let association = 0; association < count; association++) {
        const value = wideIndex ? bytes.readUInt16BE(cursor) & 0x7fff : bytes[cursor] & 0x7f;
        cursor += wideIndex ? 2 : 1;
        if (id !== primary || value === 0) continue;
        const property = definitions[value - 1];
        if (property?.name === 'ispe' && property.end - property.start >= 12) {
          size = [bytes.readUInt32BE(property.start + 4), bytes.readUInt32BE(property.start + 8)];
        }
        if (property?.name === 'irot' && property.end > property.start) rotate = Boolean(bytes[property.start] & 1);
      }
      if (size) return rotate ? size.reverse() : size;
    }
  }
  return null;
}

export function dimensions(bytes) {
  if (bytes.length < 12) return null;
  if (bytes.length >= 24 && bytes.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex'))) {
    return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)];
  }
  if (/^GIF8[79]a$/.test(bytes.toString('ascii', 0, 6))) return [bytes.readUInt16LE(6), bytes.readUInt16LE(8)];
  if (bytes.readUInt16BE(0) === 0xffd8) {
    const frames = new Set([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf]);
    let offset = 2;
    while (offset + 4 <= bytes.length && bytes[offset++] === 0xff) {
      while (offset < bytes.length && bytes[offset] === 0xff) offset++;
      const marker = bytes[offset++];
      if (marker === 0xda || marker === 0xd9 || offset + 2 > bytes.length) break;
      const length = bytes.readUInt16BE(offset);
      if (length < 2 || offset + length > bytes.length) break;
      if (frames.has(marker) && length >= 7) return [bytes.readUInt16BE(offset + 5), bytes.readUInt16BE(offset + 3)];
      offset += length;
    }
  }
  if (bytes.length >= 30 && bytes.toString('ascii', 0, 4) === 'RIFF' && bytes.toString('ascii', 8, 12) === 'WEBP') {
    const type = bytes.toString('ascii', 12, 16);
    if (type === 'VP8X') return [1 + bytes.readUIntLE(24, 3), 1 + bytes.readUIntLE(27, 3)];
    if (type === 'VP8 ' && bytes.readUIntBE(23, 3) === 0x9d012a) return [bytes.readUInt16LE(26) & 0x3fff, bytes.readUInt16LE(28) & 0x3fff];
    if (type === 'VP8L' && bytes[20] === 0x2f) {
      const packed = bytes.readUInt32LE(21);
      return [1 + (packed & 0x3fff), 1 + ((packed >>> 14) & 0x3fff)];
    }
  }
  if (bytes.toString('ascii', 4, 8) === 'ftyp') return avifSize(bytes);
  return null;
}

export async function imageSize(file) {
  const handle = await open(file, 'r');
  try {
    const {size} = await handle.stat();
    const bytes = Buffer.alloc(Math.min(2 * 1024 * 1024, size));
    const {bytesRead} = await handle.read(bytes);
    const sizePair = dimensions(bytes.subarray(0, bytesRead));
    if (!sizePair?.every(value => Number.isSafeInteger(value) && value > 0)) {
      throw new Error('Image dimensions are unavailable. Mark the source explicitly in the manifest.');
    }
    return sizePair;
  } finally {
    await handle.close();
  }
}
