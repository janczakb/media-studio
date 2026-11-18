import type { Entry } from '../contracts';
import type { TransferTransport, UploadPolicy, UploadSession } from './types';

export class TransferHttpError extends Error {
    constructor(readonly status: number, message: string) {
        super(message);
        this.name = 'TransferHttpError';
    }
}

function json(xhr: XMLHttpRequest): unknown {
    try {
        return JSON.parse(xhr.responseText);
    } catch {
        return null;
    }
}

export function createUploadTransport(options: {
    baseUrl: string;
    csrfToken?: () => string;
    credentials?: RequestCredentials;
}): TransferTransport {
    const base = options.baseUrl.replace(/\/$/, '');
    if (!/^https?:\/\//.test(base) && !base.startsWith('/'))
        throw new Error('An HTTP(S) or root-relative API URL is required.');
    const path = (w: string, suffix: string) => `${base}/workspaces/${encodeURIComponent(w)}/${suffix}`;
    const partPath = (w: string, id: string, index: number) => path(w, `upload-sessions/${encodeURIComponent(id)}/parts/${index}`);

    async function request<T>(url: string, signal: AbortSignal, method = 'GET', data?: unknown): Promise<T> {
        const headers = new Headers({ Accept: 'application/json' });
        if (method !== 'GET' && options.csrfToken)
            headers.set('X-CSRF-TOKEN', options.csrfToken());
        if (data !== undefined)
            headers.set('Content-Type', 'application/json');
        let response: Response;
        try {
            response = await fetch(url, { signal, method, headers, credentials: options.credentials ?? 'same-origin', ...(data === undefined ? {} : { body: JSON.stringify(data) }) });
        } catch (error) {
            if (error instanceof TypeError && !signal.aborted)
                throw new TransferHttpError(0, 'Network connection interrupted.');
            throw error;
        }
        const body = await response.json().catch(() => null);
        if (!response.ok)
            throw new TransferHttpError(response.status, body?.message ?? 'Upload request failed.');
        return body as T;
    }

    function xhr(url: string, method: string, body: XMLHttpRequestBodyInit, headers: Record<string, string>, signal: AbortSignal, progress: (loaded: number) => void, direct = false): Promise<XMLHttpRequest> {
        return new Promise((resolve, reject) => {
            signal.throwIfAborted();
            const x = new XMLHttpRequest();
            x.open(method, url);
            x.withCredentials = !direct && options.credentials === 'include';
            x.timeout = 120000;
            if (!direct) {
                x.setRequestHeader('Accept', 'application/json');
                if (options.csrfToken)
                    x.setRequestHeader('X-CSRF-TOKEN', options.csrfToken());
            }
            for (const [key, value] of Object.entries(headers))
                x.setRequestHeader(key, value);
            const abort = () => x.abort();
            const done = () => signal.removeEventListener('abort', abort);
            signal.addEventListener('abort', abort, { once: true });
            x.upload.onprogress = e => progress(e.lengthComputable && body instanceof FormData
                ? Math.floor(e.loaded / e.total * (body.get('file') as File).size)
                : e.loaded);
            x.onerror = () => { done(); reject(new TransferHttpError(0, 'Network connection interrupted.')); };
            x.ontimeout = () => { done(); reject(new TransferHttpError(408, 'Upload timed out.')); };
            x.onabort = () => { done(); reject(new DOMException('Upload cancelled.', 'AbortError')); };
            x.onload = () => {
                done();
                if (x.status >= 200 && x.status < 300) {
                    resolve(x);
                } else if (direct) {
                    // A provider 403 can mean the signed URL expired. A retry obtains a fresh signature.
                    reject(new TransferHttpError(x.status === 403 ? 408 : x.status, 'Storage rejected the upload part.'));
                } else {
                    const data = json(x) as { message?: string } | null;
                    reject(new TransferHttpError(x.status, data?.message ?? 'Upload failed.'));
                }
            };
            try {
                x.send(body);
            } catch (error) {
                done();
                reject(error);
            }
        });
    }

    return {
        policy: (w, s) => request<UploadPolicy>(path(w, 'upload-policy'), s),
        resolveFolders: (w, parent, paths, s) => request(path(w, 'folders/resolve'), s, 'POST', { parent, paths }),
        ordinary: async (w, parent, file, key, s, progress) => {
            const body = new FormData();
            body.append('file', file);
            if (parent)
                body.append('parent', parent);
            const response = await xhr(path(w, 'uploads'), 'POST', body, { 'Idempotency-Key': key }, s, loaded => progress(Math.min(file.size, loaded)));
            return json(response) as Entry;
        },
        createSession: (w, parent, file, key, s) => request<UploadSession>(path(w, 'upload-sessions'), s, 'POST', {
            parent, name: file.name, size: file.size, mime: file.type || 'application/octet-stream', key, direct: !!globalThis.crypto?.subtle,
        }),
        session: (w, id, s) => request<UploadSession>(path(w, `upload-sessions/${encodeURIComponent(id)}`), s),
        part: async (w, id, index, blob, s, progress, transport) => {
            s.throwIfAborted();
            const digest = globalThis.crypto?.subtle ? await crypto.subtle.digest('SHA-256', await blob.arrayBuffer()) : null;
            s.throwIfAborted();
            const sha256 = digest ? Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('') : null;
            const endpoint = partPath(w, id, index);
            if (transport === 's3-multipart') {
                if (!digest || !sha256)
                    throw new Error('Multipart uploads require a secure browser context.');
                const signed = await request<{ url: string; headers: Record<string, string> }>(`${endpoint}/sign`, s, 'POST', { sha256 });
                const url = new URL(signed.url);
                const local = url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
                const checksum = signed.headers['x-amz-checksum-sha256'];
                if ((!local && url.protocol !== 'https:') || url.username || url.password || url.origin === globalThis.location?.origin
                    || checksum !== btoa(String.fromCharCode(...new Uint8Array(digest)))) {
                    throw new Error('Invalid multipart storage signature.');
                }
                const response = await xhr(url.href, 'PUT', blob, { 'x-amz-checksum-sha256': checksum }, s, progress, true);
                const etag = response.getResponseHeader('ETag');
                if (!etag)
                    throw new Error('Storage must expose the ETag response header for multipart uploads.');
                await request(`${endpoint}/receipt`, s, 'POST', { etag });
            } else {
                await xhr(endpoint, 'PUT', blob, { 'Content-Type': 'application/octet-stream', ...(sha256 ? { 'X-Part-SHA256': sha256 } : {}) }, s, progress);
            }
        },
        complete: (w, id, s) => request<Entry>(path(w, `upload-sessions/${encodeURIComponent(id)}/complete`), s, 'POST', {}),
        cancel: async (w, id, s) => { await request(path(w, `upload-sessions/${encodeURIComponent(id)}`), s, 'DELETE'); },
    };
}
