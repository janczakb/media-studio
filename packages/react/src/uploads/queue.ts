import type { Entry } from '../contracts';
import { randomId } from '../random-id';
import { TransferHttpError } from './transport';
import type { TransferItem, TransferQueue, TransferSnapshot, TransferTransport, UploadPolicy, UploadSession } from './types';
interface RecordItem {
    item: TransferItem;
    file: File | null;
    controller: AbortController | null;
    session: UploadSession | null;
    key: string;
    generation: number;
    attempts: number;
    folderResolved: boolean;
    retryAt: number;
}
const terminal = new Set(['completed', 'cancelled']);
export function createTransferQueue(options: {
    transport: TransferTransport;
    concurrency?: number;
    maxFiles?: number;
    maxRetainedCompleted?: number;
    progressIntervalMs?: number;
    chunkThresholdBytes?: number;
    retries?: number;
    onComplete?: (entry: Entry, item: TransferItem) => void;
}): TransferQueue {
    const positive = (n: number, name: string, min = 1, max = 10000) => { if (!Number.isInteger(n) || n < min || n > max)
        throw new Error(`Invalid ${name}.`); return n; };
    const concurrency = positive(options.concurrency ?? 3, 'concurrency', 1, 8), maxFiles = positive(options.maxFiles ?? 1000, 'maxFiles'), retained = positive(options.maxRetainedCompleted ?? 100, 'completed retention', 0, 1000), interval = positive(options.progressIntervalMs ?? 50, 'progress interval', 16, 1000), retries = positive(options.retries ?? 2, 'retries', 0, 5);
    const records = new Map<string, RecordItem>(), listeners = new Set<() => void>(), itemListeners = new Map<string, Set<() => void>>();
    const auxiliary = new Set<AbortController>(), policies = new Map<string, {
        promise: Promise<UploadPolicy>;
        expires: number;
        controller: AbortController;
    }>(), permits = new Map<string, {
        active: number;
        waiters: (() => void)[];
    }>();
    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let ids: string[] = [], active = 0, destroyed = false, paused = false, timer: ReturnType<typeof setTimeout> | null = null, snapshot: TransferSnapshot = { ids: [], activeCount: 0, pausedCount: 0, pendingCount: 0, isPaused: false, completedCount: 0, failedCount: 0, totalBytes: 0, loadedBytes: 0 };
    const dirty = new Set<string>();
    function publish() {
        timer = null;
        if (destroyed)
            return;
        let completedCount = 0, failedCount = 0, pausedCount = 0, pendingCount = 0, totalBytes = 0, loadedBytes = 0;
        for (const r of records.values()) {
            if (r.item.state === 'completed')
                completedCount++;
            if (r.item.state === 'error')
                failedCount++;
            if (r.item.state === 'paused') pausedCount++;
            if (!terminal.has(r.item.state) && r.item.state !== 'error') pendingCount++;
            totalBytes += r.item.size;
            loadedBytes += r.item.loaded;
        }
        snapshot = { ids: Object.freeze([...ids]), activeCount: active, pausedCount, pendingCount, isPaused: paused, completedCount, failedCount, totalBytes, loadedBytes };
        for (const id of dirty)
            for (const fn of itemListeners.get(id) ?? [])
                fn();
        dirty.clear();
        for (const fn of listeners)
            fn();
    }
    function notify(id?: string, immediate = false) { if (id)
        dirty.add(id); if (immediate) {
        if (timer)
            clearTimeout(timer);
        publish();
    }
    else if (!timer)
        timer = setTimeout(publish, interval); }
    function change(r: RecordItem, patch: Partial<TransferItem>, immediate = false) { r.item = { ...r.item, ...patch }; notify(r.item.id, immediate); }
    function prune() { const finished = ids.filter(id => terminal.has(records.get(id)!.item.state)); for (const id of finished.slice(0, Math.max(0, finished.length - retained))) {
        records.delete(id);
        dirty.add(id);
    } ids = ids.filter(id => records.has(id)); }
    function safePath(file: File) { const value = file.webkitRelativePath || file.name; if (value.length > 4096 || value.includes('\\') || value.startsWith('/') || value.split('/').some(s => !s || s === '.' || s === '..' || /[\x00-\x1f\x7f]/.test(s)))
        throw new Error('Unsafe folder upload path.'); return value; }
    function policyFor(workspace: string): Promise<UploadPolicy> { let cached = policies.get(workspace); if (cached && cached.expires > Date.now())
        return cached.promise; cached?.controller.abort(); const controller = new AbortController(); const promise = options.transport.policy(workspace, controller.signal).catch(error => { if (policies.get(workspace)?.controller === controller) policies.delete(workspace); throw error; }); policies.set(workspace, { promise, expires: Date.now() + 60000, controller }); if (policies.size > 32) {
        const key = policies.keys().next().value;
        if (key && key !== workspace) {
            policies.get(key)?.controller.abort();
            policies.delete(key);
        }
    } return promise; }
    async function permit(workspace: string, limit: number, signal: AbortSignal): Promise<() => void> { let scope = permits.get(workspace); if (!scope) {
        scope = { active: 0, waiters: [] };
        permits.set(workspace, scope);
    } while (scope.active >= limit) {
        await new Promise<void>((resolve, reject) => { const wake = () => { signal.removeEventListener('abort', abort); resolve(); }; const abort = () => { scope!.waiters = scope!.waiters.filter(fn => fn !== wake); reject(signal.reason ?? new DOMException('Cancelled', 'AbortError')); }; scope!.waiters.push(wake); signal.addEventListener('abort', abort, { once: true }); });
    } signal.throwIfAborted(); scope.active++; return () => { scope!.active--; const wake = scope!.waiters.shift(); if (wake)
        wake();
    else if (scope!.active === 0)
        permits.delete(workspace); }; }
    function schedule() {
        if (destroyed || paused)
            return;
        for (const id of ids) {
            if (active >= concurrency)
                break;
            const r = records.get(id)!;
            if (r.item.state !== 'queued' || r.controller || r.retryAt > Date.now())
                continue;
            active++;
            const controller = new AbortController();
            r.controller = controller;
            const generation = ++r.generation;
            void run(r, controller.signal).catch(error => {
                if (destroyed || r.generation !== generation || controller.signal.aborted)
                    return;
                if (error instanceof TransferHttpError && error.status === 403) {
                    policies.get(r.item.workspaceId)?.controller.abort();
                    policies.delete(r.item.workspaceId);
                }
                if (r.attempts < retries && (error instanceof TransferHttpError && (error.status === 0 || error.status === 408 || error.status === 429 || error.status >= 500))) {
                    r.attempts++;
                    r.retryAt = Date.now() + Math.min(30000, 500 * 2 ** (r.attempts - 1));
                    change(r, { state: 'queued', error: null });
                }
                else
                    change(r, { state: 'error', error: error instanceof Error ? error.message : 'Upload failed.' });
            }).finally(() => { r.controller = null; active--; prune(); notify(r.item.id, true); schedule(); });
        }
        if (retryTimer) {
            clearTimeout(retryTimer);
            retryTimer = null;
        }
        const next = ids.map(id => records.get(id)!).filter(r => r.item.state === 'queued' && r.retryAt > Date.now()).reduce((min, r) => Math.min(min, r.retryAt), Infinity);
        if (Number.isFinite(next))
            retryTimer = setTimeout(() => { retryTimer = null; schedule(); }, Math.max(0, next - Date.now()));
        notify();
    }
    async function run(r: RecordItem, signal: AbortSignal) {
        if (!r.file)
            return;
        const file = r.file;
        change(r, { state: 'resolving', error: null });
        const policy = await policyFor(r.item.workspaceId);
        signal.throwIfAborted();
        if (!Number.isSafeInteger(policy.maxBytes) || !Number.isInteger(policy.maxConcurrent) || policy.maxConcurrent < 1 || !Number.isInteger(policy.maxParts) || policy.maxParts < 1)
            throw new Error('Invalid server upload policy.');
        if (r.item.relativePath.includes('/') && !r.folderResolved) {
            const path = r.item.relativePath.slice(0, r.item.relativePath.lastIndexOf('/'));
            const result = await options.transport.resolveFolders(r.item.workspaceId, r.item.parentId, [path], signal);
            const target = result.items.find(i => i.path === path);
            if (!target)
                throw new Error('Folder destination could not be resolved.');
            change(r, { parentId: target.id });
            r.folderResolved = true;
        }
        const chunks = r.session !== null || (policy.enabled && (file.size > policy.ordinaryMaxBytes
            || (file.size <= policy.maxBytes && (file.size >= (options.chunkThresholdBytes ?? 8 * 1024 * 1024)
                || (policy.multipartThresholdBytes != null && file.size >= policy.multipartThresholdBytes)))));
        if (chunks && r.session === null && file.size > policy.maxBytes)
            throw new Error('File exceeds the chunk upload size limit.');
        const release = await permit(r.item.workspaceId, Math.max(1, Math.min(concurrency, policy.maxConcurrent)), signal);
        try {
            change(r, { state: 'uploading' });
            let entry: Entry;
            if (!chunks) {
                if (file.size > policy.ordinaryMaxBytes)
                    throw new Error('Chunk uploads are disabled for this file size.');
                entry = await options.transport.ordinary(r.item.workspaceId, r.item.parentId, file, r.key, signal, loaded => { if (!signal.aborted) change(r, { loaded: Math.min(file.size, loaded) }); });
            }
            else {
                r.session = r.session ? await options.transport.session(r.item.workspaceId, r.session.id, signal) : await options.transport.createSession(r.item.workspaceId, r.item.parentId, file, r.key, signal);
                const session = r.session;
                if (session.size !== file.size || session.chunkBytes < 65536 || session.chunkBytes > 33554432)
                    throw new Error('Upload session does not match the selected file.');
                if (session.entry) {
                    entry = session.entry;
                }
                else {
                    const accepted = new Set(session.acceptedParts);
                    let loaded = 0;
                    const count = Math.ceil(file.size / session.chunkBytes);
                    if (count > policy.maxParts)
                        throw new Error('Too many upload parts.');
                    for (let index = 0; index < count; index++) {
                        const blob = file.slice(index * session.chunkBytes, Math.min(file.size, (index + 1) * session.chunkBytes));
                        if (!accepted.has(index)) {
                            await options.transport.part(r.item.workspaceId, session.id, index, blob, signal, bytes => { if (!signal.aborted) change(r, { loaded: Math.min(file.size, loaded + bytes) }); }, session.transport);
                            accepted.add(index);
                            session.acceptedParts = [...accepted];
                        }
                        loaded += blob.size;
                        change(r, { loaded });
                    }
                    change(r, { state: 'finalizing', loaded: file.size });
                    entry = await options.transport.complete(r.item.workspaceId, session.id, signal);
                }
            }
            signal.throwIfAborted();
            r.file = null;
            change(r, { state: 'completed', loaded: file.size, entry }, true);
            try {
                options.onComplete?.(entry, r.item);
            }
            catch { /* Consumer callbacks cannot change accepted transfer state. */ }
        }
        finally {
            release();
        }
    }
    function control(id: string, state: 'paused' | 'cancelled') { const r = records.get(id); if (!r || terminal.has(r.item.state) || r.item.state === 'finalizing')
        return; r.generation++; r.controller?.abort(); change(r, { state, error: null }, true); if (state === 'cancelled') {
        r.file = null;
        if (r.session) {
            const controller = new AbortController();
            auxiliary.add(controller);
            void options.transport.cancel(r.item.workspaceId, r.session.id, controller.signal).catch(() => { }).finally(() => auxiliary.delete(controller));
        }
    } }
    return {
        invalidatePolicy(workspace) { policies.delete(workspace); },
        enqueue(files, context) { if (destroyed)
            throw new Error('Transfer queue is destroyed.'); const admitted: File[] = []; for (const file of files) {
            if (records.size + admitted.length >= maxFiles)
                throw new Error('Upload queue is full.');
            safePath(file);
            admitted.push(file);
        } const result: string[] = []; for (const file of admitted) {
            const id = randomId();
            records.set(id, { item: { id, name: file.name, relativePath: safePath(file), workspaceId: context.workspaceId, parentId: context.parentId, size: file.size, loaded: 0, state: paused ? 'paused' : 'queued', error: null }, file, controller: null, session: null, key: randomId(), generation: 0, attempts: 0, folderResolved: false, retryAt: 0 });
            result.push(id);
        } ids = [...ids, ...result]; notify(undefined, true); schedule(); return result; },
        subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); }, getSnapshot: () => snapshot,
        subscribeItem(id, fn) { let set = itemListeners.get(id); if (!set) {
            set = new Set();
            itemListeners.set(id, set);
        } set.add(fn); return () => { set!.delete(fn); if (set!.size === 0)
            itemListeners.delete(id); }; }, getItem: id => records.get(id)?.item ?? null,
        pauseAll() {
            if (destroyed || paused) return;
            paused = true;
            if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
            // Finalization may already have committed remotely. Let it settle; never
            // replay a commit merely to make the tray look paused.
            for (const r of records.values()) {
                if (!['queued', 'resolving', 'uploading'].includes(r.item.state)) continue;
                r.generation++;
                r.controller?.abort();
                r.item = {...r.item, state: 'paused', error: null};
                dirty.add(r.item.id);
            }
            notify(undefined, true);
        },
        resumeAll() {
            if (destroyed) return;
            paused = false;
            for (const r of records.values()) {
                if (r.item.state !== 'paused') continue;
                r.retryAt = 0;
                r.item = {...r.item, state: 'queued'};
                dirty.add(r.item.id);
            }
            notify(undefined, true);
            schedule();
        },
        pause: id => control(id, 'paused'), cancel: id => control(id, 'cancelled'), resume(id) { const r = records.get(id); if (!paused && r && r.item.state === 'paused') {
            change(r, { state: 'queued' }, true);
            schedule();
        } }, retry(id) { const r = records.get(id); if (r && r.item.state === 'error') {
            r.attempts = 0;
            r.retryAt = 0;
            change(r, { state: paused ? 'paused' : 'queued', error: null }, true);
            schedule();
        } },
        clearFinished() { for (const id of ids)
            if (terminal.has(records.get(id)!.item.state)) {
                records.delete(id);
                dirty.add(id);
            } ids = ids.filter(id => records.has(id)); notify(undefined, true); },
        destroy() { destroyed = true; if (timer)
            clearTimeout(timer); if (retryTimer)
            clearTimeout(retryTimer); for (const controller of auxiliary)
            controller.abort(); auxiliary.clear(); for (const entry of policies.values())
            entry.controller.abort(); policies.clear(); for (const r of records.values()) {
            r.controller?.abort();
            r.file = null;
        } records.clear(); ids = []; listeners.clear(); itemListeners.clear(); dirty.clear(); },
    };
}
