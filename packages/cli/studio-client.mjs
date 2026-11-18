import {createHash, randomBytes} from 'node:crypto';
import {createReadStream} from 'node:fs';
import {lstat} from 'node:fs/promises';
import {basename} from 'node:path';
import {Readable} from 'node:stream';
import {setTimeout as sleep} from 'node:timers/promises';

export class StudioError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

function secureUrl(value) {
  if (!value) throw new Error('Set FLEX_STUDIO_URL to the Studio MCP endpoint.');
  const url = new URL(value);
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) || url.hostname.endsWith('.test');
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) || url.username || url.password || url.hash) {
    throw new Error('Use an HTTPS URL without credentials. HTTP is allowed for local development.');
  }
  return url;
}

async function retry(operation) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await operation();
    } catch (error) {
      if (attempt === 3 || ![429, 502, 503, 504, 0].includes(error.status)) throw error;
      const delay = Math.min(60, error.retryAfter ?? 2 ** attempt);
      await sleep(delay * 1000 + Math.random() * 150);
    }
  }
}

export async function fileDigest(file, range = {}) {
  const hash = createHash('sha256');
  for await (const bytes of createReadStream(file, range)) hash.update(bytes);
  return hash.digest('hex');
}

function multipart(fields) {
  const boundary = 'flex-studio-' + randomBytes(18).toString('hex');
  const content = fields.map(field => {
    const name = field.key;
    const filename = field.filename?.replaceAll('"', '%22');
    const header = Buffer.from('--' + boundary + '\r\nContent-Disposition: form-data; name="' + name + '"' +
      (field.file ? '; filename="' + filename + '"\r\nContent-Type: application/octet-stream' : '') + '\r\n\r\n');
    const text = field.file ? null : Buffer.from(field.value);
    return {...field, header, text};
  });
  const ending = Buffer.from('--' + boundary + '--\r\n');
  const length = content.reduce((total, field) => total + field.header.length + (field.file?.bytes ?? field.text.length) + 2, ending.length);
  async function* body() {
    for (const field of content) {
      yield field.header;
      if (field.file) yield* createReadStream(field.file.absolute);
      else yield field.text;
      yield Buffer.from('\r\n');
    }
    yield ending;
  }
  return {headers: {'content-type': 'multipart/form-data; boundary=' + boundary, 'content-length': String(length)}, body: Readable.toWeb(Readable.from(body())), duplex: 'half'};
}

export class StudioClient {
  #id = 0;
  #folders = new Map();

  constructor(endpoint, token) {
    this.endpoint = secureUrl(endpoint);
    if (!token || /[\r\n]/.test(token)) throw new Error('Set FLEX_STUDIO_TOKEN to your managed MCP token.');
    this.token = token;
  }

  transferPath(path) {
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//') || path.includes('\\')) throw new Error('Invalid transfer path from Studio.');
    const url = new URL(path, this.endpoint);
    if (url.origin !== this.endpoint.origin) throw new Error('Transfer path changed origin.');
    return url;
  }

  async request(url, options = {}, authenticated = true, acceptRedirect = false) {
    let response;
    try {
      response = await fetch(url, {...options, redirect: 'manual', signal: AbortSignal.timeout(120000),
        headers: {...options.headers, ...(authenticated ? {authorization: 'Bearer ' + this.token, accept: 'application/json, text/event-stream'} : {})}});
    } catch {
      throw new StudioError('Connection interrupted or timed out.', 0);
    }
    if (acceptRedirect && [301, 302, 303, 307, 308].includes(response.status)) return response;
    if (!response.ok) {
      await response.body?.cancel();
      const error = new StudioError('Studio transfer returned HTTP ' + response.status + '.', response.status);
      const header = response.headers.get('retry-after');
      if (header && /^\d+$/.test(header)) error.retryAfter = Number(header);
      throw error;
    }
    return response;
  }

  async tool(name, args, safe = true) {
    const call = async () => {
      const response = await this.request(this.endpoint, {method: 'POST', headers: {'content-type': 'application/json'},
        body: JSON.stringify({jsonrpc: '2.0', id: ++this.#id, method: 'tools/call', params: {name, arguments: args}})});
      const reply = await response.json();
      if (reply.error || reply.result?.isError) {
        const code = reply.result?.content?.[0]?.text?.match(/^[a-z_]+(?=:)/)?.[0];
        throw new StudioError(name + ' failed' + (code ? ' (' + code + ')' : '') + '.', code === 'rate_limited' ? 429 : 400);
      }
      return reply.result?.structuredContent ?? JSON.parse(reply.result?.content?.[0]?.text);
    };
    return safe ? retry(call) : call();
  }

  async download(path) {
    const from = this.transferPath(path);
    const response = await this.request(from, {}, true, true);
    if (response.ok) return response;
    const location = response.headers.get('location');
    await response.body?.cancel();
    if (!location) throw new Error('Studio returned a download redirect without a location.');
    const destination = secureUrl(new URL(location, from));
    return this.request(destination, {}, destination.origin === this.endpoint.origin);
  }

  async folder(workspace, base, parts) {
    if (!parts.length) return base;
    const path = parts.join('/');
    const cacheKey = JSON.stringify([workspace, base, path]);
    if (!this.#folders.has(cacheKey)) {
      if (this.#folders.size >= 1024) this.#folders.delete(this.#folders.keys().next().value);
      const pending = this.tool('studio_resolve_import_folder', {workspace, parent: base, path})
        .then(result => result.id)
        .catch(error => {
          this.#folders.delete(cacheKey);
          throw error;
        });
      this.#folders.set(cacheKey, pending);
    }
    return this.#folders.get(cacheKey);
  }

  async upload(workspace, parent, group, policy, paths, direct) {
    const files = [group.source, ...group.variants];
    const fingerprints = [];
    for (const file of files) {
      const actual = await lstat(file.absolute);
      if (!actual.isFile() || actual.size !== file.bytes || actual.mtimeMs !== file.mtimeMs) {
        throw new Error('A local file changed after it was planned.');
      }
      fingerprints.push([file.preset ?? '', await fileDigest(file.absolute)]);
    }
    const key = createHash('sha256').update(JSON.stringify([workspace, parent, group.id, group.target, fingerprints, group.roles])).digest('hex');
    if (!group.variants.length && policy.enabled) return this.#chunks(workspace, parent, group, key, direct);

    const path = group.variants.length ? await paths.bundle() : await paths.ordinary();
    if (!path) throw new Error('Upload transport is unavailable.');
    if (files.reduce((total, file) => total + file.bytes, 0) > policy.ordinaryMaxBytes) {
      throw new Error(group.variants.length ? 'Image bundle exceeds the ordinary upload limit. Use --mode rebuild or increase the server limit.' : 'File exceeds the ordinary upload limit. Enable resumable uploads on the server.');
    }
    const fields = [
      ...(parent ? [{key: 'parent', value: parent}] : []),
      {key: 'file', filename: group.target, file: group.source},
      ...group.variants.map(file => ({key: 'variants[' + file.preset + ']', filename: basename(file.path), file})),
      ...Object.entries(group.roles).map(([role, value]) => ({key: 'roles[' + role + ']', value})),
    ];
    const response = await retry(() => {
      const data = multipart(fields);
      return this.request(this.transferPath(path), {...data, method: 'POST', headers: {...data.headers, 'idempotency-key': key}});
    });
    return response.json();
  }

  async #chunks(workspace, parent, group, key, direct) {
    const file = group.source;
    const session = await this.tool('studio_begin_upload', {workspace, parent, name: group.target, size: file.bytes, key, direct});
    if (session.state === 'completed' && session.entry) return session.entry;
    if (!Number.isInteger(session.chunkBytes) || session.chunkBytes < 65536 || session.chunkBytes > 32 * 1024 * 1024) {
      throw new Error('Studio returned an invalid chunk size.');
    }
    const sessionArgs = {workspace, session: session.id};
    const accepted = new Set(session.acceptedParts);
    for (let start = 0, part = 0; start < file.bytes; start += session.chunkBytes, part++) {
      if (accepted.has(part)) continue;
      const end = Math.min(file.bytes, start + session.chunkBytes) - 1;
      const sha256 = await fileDigest(file.absolute, {start, end});
      const options = () => ({method: 'PUT', body: Readable.toWeb(createReadStream(file.absolute, {start, end})),
        duplex: 'half', headers: {'content-length': String(end - start + 1)}});
      if (session.transport === 's3-multipart') {
        const uploadPart = async () => {
          const signed = await this.tool('studio_sign_upload_part', {...sessionArgs, part, sha256});
          const request = options();
          const headers = Object.fromEntries(Object.entries(signed.headers ?? {}).filter(([name]) => name.toLowerCase() === 'x-amz-checksum-sha256'));
          const result = await this.request(secureUrl(signed.url), {...request, headers: {...request.headers, ...headers}}, false);
          await result.body?.cancel();
          const etag = result.headers.get('etag');
          if (!etag) throw new Error('The storage provider did not return an ETag.');
          return etag;
        };
        const etag = await retry(uploadPart);
        await this.tool('studio_record_upload_part', {...sessionArgs, part, etag});
      } else if (session.transport === 'local') {
        const path = this.transferPath(session.partPathPrefix + part);
        await retry(async () => {
          const request = options();
          const response = await this.request(path, {...request, headers: {...request.headers, 'content-type': 'application/octet-stream', 'x-part-sha256': sha256}});
          await response.body?.cancel();
        });
      } else {
        throw new Error('Studio returned an unknown upload transport.');
      }
    }
    return this.tool('studio_complete_upload', sessionArgs);
  }
}
