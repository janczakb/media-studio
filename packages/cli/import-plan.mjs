import {createReadStream} from 'node:fs';
import {lstat, opendir, realpath} from 'node:fs/promises';
import {basename, dirname, extname, isAbsolute, resolve} from 'node:path';
import {imageSize} from './image-size.mjs';

const preset = /^[a-z][a-z0-9_-]{0,22}$/;
const imageExtension = /\.(?:jpe?g|png|webp|avif|gif)$/i;

function pathParts(path, allowEmpty = false) {
  if (allowEmpty && path === '') return [];
  if (typeof path !== 'string' || /["\\:\x00-\x1f\x7f]/.test(path) || isAbsolute(path)) throw new Error('Expected a relative path.');
  const parts = path.split('/');
  if (parts.length > 32 || parts.some(part => !part || part === '.' || part === '..' || [...part].length > 191 || part.trim() !== part)) {
    throw new Error('Unsafe or unsupported file or folder name.');
  }
  return parts;
}

async function localFile(root, path) {
  const input = resolve(root, ...pathParts(path));
  const real = await realpath(input);
  const info = await lstat(input);
  if (real !== input || !info.isFile() || info.size === 0) throw new Error('Expected a nonempty regular file inside the import directory.');
  return {path, absolute: real, bytes: info.size, mtimeMs: info.mtimeMs};
}

async function* lines(path) {
  let buffer = '';
  for await (const chunk of createReadStream(path, {encoding: 'utf8', highWaterMark: 16 * 1024})) {
    buffer += chunk;
    let newline;
    while ((newline = buffer.indexOf('\n')) !== -1) {
      if (newline > 128 * 1024) throw new Error('A manifest record exceeds 128 KiB.');
      const row = buffer.slice(0, newline).trim();
      buffer = buffer.slice(newline + 1);
      if (row) yield row;
    }
    if (buffer.length > 128 * 1024) throw new Error('A manifest record exceeds 128 KiB.');
  }
  if (buffer.trim()) yield buffer.trim();
}

async function* scan(root, folder, rule, maxGroups) {
  const groups = new Map();
  const directory = await opendir(resolve(root, folder));
  for await (const entry of directory) {
    const path = folder ? folder + '/' + entry.name : entry.name;
    if (entry.isDirectory()) {
      pathParts(path);
      yield* scan(root, path, rule, maxGroups);
      continue;
    }
    if (!entry.isFile()) continue;
    if (!rule) {
      yield {id: path, folder, files: [{path, original: true}]};
      continue;
    }
    const match = rule.exec(entry.name);
    if (!match || !imageExtension.test(entry.name)) continue;
    const {key, variant} = match.groups ?? {};
    if (!key || !variant || pathParts(key).length !== 1) throw new Error('The pattern requires named key and variant groups.');
    if (!groups.has(key)) {
      if (groups.size >= maxGroups) throw new Error('Directory grouping limit reached. Use a JSONL manifest for a large flat directory.');
      groups.set(key, {id: folder ? folder + '/' + key : key, folder, base: key, files: []});
    }
    const files = groups.get(key).files;
    if (files.length >= 13) throw new Error('An image group may contain up to 13 files.');
    files.push({path, name: variant === 'original' ? undefined : /^\d+$/.test(variant) ? 'size-'+variant : variant, original: variant === 'original'});
  }
  yield* groups.values();
}

async function chooseSource(files) {
  const original = files.filter(file => file.original);
  if (original.length > 1) throw new Error('A group can have only one original.');
  if (original.length === 1 || files.length === 1) return original[0] ?? files[0];
  const measured = [];
  for (const file of files) {
    const [width, height] = await imageSize(file.absolute);
    measured.push({file, width, height});
  }
  measured.sort((a, b) => b.width * b.height - a.width * a.height || a.file.path.localeCompare(b.file.path, 'en'));
  const [largest, next] = measured;
  const ratio = largest.width / largest.height;
  if (measured.some(image => Math.abs((image.width / image.height) / ratio - 1) > 0.025)) {
    throw new Error('The group contains different aspect ratios. Mark the intended source in the manifest.');
  }
  if (next.width * next.height === largest.width * largest.height) {
    throw new Error('Several conversions share the largest resolution. Mark the intended source in the manifest.');
  }
  return largest.file;
}

async function planGroup(root, group, mode) {
  if (typeof group?.id !== 'string' || group.id.length < 1 || group.id.length > 1024 || !Array.isArray(group.files) || group.files.length < 1 || group.files.length > 13) {
    throw new Error('Expected a group ID and one to thirteen file records.');
  }
  const seen = new Set();
  const files = [];
  for (const record of group.files) {
    if (!record || (record.original !== undefined && typeof record.original !== 'boolean')) throw new Error('Invalid file record.');
    const file = await localFile(root, record.path);
    if (seen.has(file.path)) throw new Error('A file appears more than once in the group.');
    seen.add(file.path);
    files.push({...file, preset: record.name, original: record.original});
  }
  if (files.length > 1 && files.some(file => !imageExtension.test(file.path))) throw new Error('Multi-file groups must contain supported images.');
  const source = await chooseSource(files);
  const sourceFolder = dirname(source.path);
  const folder = pathParts(group.folder ?? (sourceFolder === '.' ? '' : sourceFolder), true);
  const target = group.target ?? (group.base ? group.base + extname(source.path).toLowerCase() : basename(source.path));
  if (pathParts(target).length !== 1 || target.split('.').at(-1).toLowerCase() !== source.path.split('.').at(-1).toLowerCase()) {
    throw new Error('The target must be a file name with the same extension as the selected source.');
  }
  const others = files.filter(file => file !== source);
  const variants = mode === 'keep' ? others.sort((a, b) => String(a.preset).localeCompare(String(b.preset), 'en')) : [];
  const roles = group.roles ?? {};
  if (!roles || typeof roles !== 'object' || Array.isArray(roles)) throw new Error('Invalid display roles.');
  const names = new Set();
  for (const variant of variants) {
    if (!preset.test(variant.preset ?? '') || names.has(variant.preset)) throw new Error('Each retained conversion needs a unique lowercase preset name.');
    names.add(variant.preset);
  }
  if (mode === 'keep' && Object.entries(roles).some(([role, name]) => !['thumbnail', 'preview'].includes(role) || !names.has(name))) {
    throw new Error('Display roles must reference retained conversions.');
  }
  return {id: group.id, folder, target, source, variants, roles: mode === 'keep' ? Object.fromEntries(Object.entries(roles).sort(([a], [b]) => a.localeCompare(b, 'en'))) : {},
    skipped: mode === 'rebuild' ? others.map(file => file.path) : []};
}

export async function* importPlan(rootPath, {manifest, pattern, mode = 'rebuild', maxGroups = 10000} = {}) {
  const root = await realpath(rootPath);
  if (!(await lstat(root)).isDirectory()) throw new Error('Import source must be a directory.');
  if (manifest && pattern) throw new Error('Choose a manifest or a naming pattern.');
  if (!['keep', 'rebuild'].includes(mode)) throw new Error('Expected --mode keep or --mode rebuild.');
  const rule = pattern ? new RegExp(pattern, 'u') : null;
  if (rule && (!pattern.startsWith('^') || !pattern.endsWith('$') || !pattern.includes('(?<key>') || !pattern.includes('(?<variant>'))) {
    throw new Error('The anchored pattern requires named key and variant groups.');
  }
  if (manifest) {
    let row = 0;
    for await (const line of lines(manifest)) {
      row++;
      try {
        yield await planGroup(root, JSON.parse(line), mode);
      } catch (error) {
        throw new Error('Manifest record ' + row + ': ' + error.message, {cause: error});
      }
    }
    return;
  }
  for await (const group of scan(root, '', rule, maxGroups)) yield await planGroup(root, group, mode);
}
