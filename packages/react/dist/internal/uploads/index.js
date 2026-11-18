// src/random-id.ts
function randomId() {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = bytes[6] & 15 | 64;
  bytes[8] = bytes[8] & 63 | 128;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join("-");
}

// src/uploads/transport.ts
var TransferHttpError = class extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
    this.name = "TransferHttpError";
  }
  status;
};
function json(xhr) {
  try {
    return JSON.parse(xhr.responseText);
  } catch {
    return null;
  }
}
function createUploadTransport(options) {
  const base = options.baseUrl.replace(/\/$/, "");
  if (!/^https?:\/\//.test(base) && !base.startsWith("/"))
    throw new Error("An HTTP(S) or root-relative API URL is required.");
  const path = (w, suffix) => `${base}/workspaces/${encodeURIComponent(w)}/${suffix}`;
  const partPath = (w, id, index) => path(w, `upload-sessions/${encodeURIComponent(id)}/parts/${index}`);
  async function request(url, signal, method = "GET", data) {
    const headers = new Headers({ Accept: "application/json" });
    if (method !== "GET" && options.csrfToken)
      headers.set("X-CSRF-TOKEN", options.csrfToken());
    if (data !== void 0)
      headers.set("Content-Type", "application/json");
    let response;
    try {
      response = await fetch(url, { signal, method, headers, credentials: options.credentials ?? "same-origin", ...data === void 0 ? {} : { body: JSON.stringify(data) } });
    } catch (error) {
      if (error instanceof TypeError && !signal.aborted)
        throw new TransferHttpError(0, "Network connection interrupted.");
      throw error;
    }
    const body = await response.json().catch(() => null);
    if (!response.ok)
      throw new TransferHttpError(response.status, body?.message ?? "Upload request failed.");
    return body;
  }
  function xhr(url, method, body, headers, signal, progress, direct = false) {
    return new Promise((resolve, reject) => {
      signal.throwIfAborted();
      const x = new XMLHttpRequest();
      x.open(method, url);
      x.withCredentials = !direct && options.credentials === "include";
      x.timeout = 12e4;
      if (!direct) {
        x.setRequestHeader("Accept", "application/json");
        if (options.csrfToken)
          x.setRequestHeader("X-CSRF-TOKEN", options.csrfToken());
      }
      for (const [key, value] of Object.entries(headers))
        x.setRequestHeader(key, value);
      const abort = () => x.abort();
      const done = () => signal.removeEventListener("abort", abort);
      signal.addEventListener("abort", abort, { once: true });
      x.upload.onprogress = (e) => progress(e.lengthComputable && body instanceof FormData ? Math.floor(e.loaded / e.total * body.get("file").size) : e.loaded);
      x.onerror = () => {
        done();
        reject(new TransferHttpError(0, "Network connection interrupted."));
      };
      x.ontimeout = () => {
        done();
        reject(new TransferHttpError(408, "Upload timed out."));
      };
      x.onabort = () => {
        done();
        reject(new DOMException("Upload cancelled.", "AbortError"));
      };
      x.onload = () => {
        done();
        if (x.status >= 200 && x.status < 300) {
          resolve(x);
        } else if (direct) {
          reject(new TransferHttpError(x.status === 403 ? 408 : x.status, "Storage rejected the upload part."));
        } else {
          const data = json(x);
          reject(new TransferHttpError(x.status, data?.message ?? "Upload failed."));
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
    policy: (w, s) => request(path(w, "upload-policy"), s),
    resolveFolders: (w, parent, paths, s) => request(path(w, "folders/resolve"), s, "POST", { parent, paths }),
    ordinary: async (w, parent, file, key, s, progress) => {
      const body = new FormData();
      body.append("file", file);
      if (parent)
        body.append("parent", parent);
      const response = await xhr(path(w, "uploads"), "POST", body, { "Idempotency-Key": key }, s, (loaded) => progress(Math.min(file.size, loaded)));
      return json(response);
    },
    createSession: (w, parent, file, key, s) => request(path(w, "upload-sessions"), s, "POST", {
      parent,
      name: file.name,
      size: file.size,
      mime: file.type || "application/octet-stream",
      key,
      direct: !!globalThis.crypto?.subtle
    }),
    session: (w, id, s) => request(path(w, `upload-sessions/${encodeURIComponent(id)}`), s),
    part: async (w, id, index, blob, s, progress, transport) => {
      s.throwIfAborted();
      const digest = globalThis.crypto?.subtle ? await crypto.subtle.digest("SHA-256", await blob.arrayBuffer()) : null;
      s.throwIfAborted();
      const sha256 = digest ? Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("") : null;
      const endpoint = partPath(w, id, index);
      if (transport === "s3-multipart") {
        if (!digest || !sha256)
          throw new Error("Multipart uploads require a secure browser context.");
        const signed = await request(`${endpoint}/sign`, s, "POST", { sha256 });
        const url = new URL(signed.url);
        const local = url.protocol === "http:" && ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
        const checksum = signed.headers["x-amz-checksum-sha256"];
        if (!local && url.protocol !== "https:" || url.username || url.password || url.origin === globalThis.location?.origin || checksum !== btoa(String.fromCharCode(...new Uint8Array(digest)))) {
          throw new Error("Invalid multipart storage signature.");
        }
        const response = await xhr(url.href, "PUT", blob, { "x-amz-checksum-sha256": checksum }, s, progress, true);
        const etag = response.getResponseHeader("ETag");
        if (!etag)
          throw new Error("Storage must expose the ETag response header for multipart uploads.");
        await request(`${endpoint}/receipt`, s, "POST", { etag });
      } else {
        await xhr(endpoint, "PUT", blob, { "Content-Type": "application/octet-stream", ...sha256 ? { "X-Part-SHA256": sha256 } : {} }, s, progress);
      }
    },
    complete: (w, id, s) => request(path(w, `upload-sessions/${encodeURIComponent(id)}/complete`), s, "POST", {}),
    cancel: async (w, id, s) => {
      await request(path(w, `upload-sessions/${encodeURIComponent(id)}`), s, "DELETE");
    }
  };
}

// src/uploads/queue.ts
var terminal = /* @__PURE__ */ new Set(["completed", "cancelled"]);
function createTransferQueue(options) {
  const positive = (n, name, min = 1, max = 1e4) => {
    if (!Number.isInteger(n) || n < min || n > max)
      throw new Error(`Invalid ${name}.`);
    return n;
  };
  const concurrency = positive(options.concurrency ?? 3, "concurrency", 1, 8), maxFiles = positive(options.maxFiles ?? 1e3, "maxFiles"), retained = positive(options.maxRetainedCompleted ?? 100, "completed retention", 0, 1e3), interval = positive(options.progressIntervalMs ?? 50, "progress interval", 16, 1e3), retries = positive(options.retries ?? 2, "retries", 0, 5);
  const records = /* @__PURE__ */ new Map(), listeners = /* @__PURE__ */ new Set(), itemListeners = /* @__PURE__ */ new Map();
  const auxiliary = /* @__PURE__ */ new Set(), policies = /* @__PURE__ */ new Map(), permits = /* @__PURE__ */ new Map();
  let retryTimer = null;
  let ids = [], active = 0, destroyed = false, paused = false, timer = null, snapshot = { ids: [], activeCount: 0, pausedCount: 0, pendingCount: 0, isPaused: false, completedCount: 0, failedCount: 0, totalBytes: 0, loadedBytes: 0 };
  const dirty = /* @__PURE__ */ new Set();
  function publish() {
    timer = null;
    if (destroyed)
      return;
    let completedCount = 0, failedCount = 0, pausedCount = 0, pendingCount = 0, totalBytes = 0, loadedBytes = 0;
    for (const r of records.values()) {
      if (r.item.state === "completed")
        completedCount++;
      if (r.item.state === "error")
        failedCount++;
      if (r.item.state === "paused") pausedCount++;
      if (!terminal.has(r.item.state) && r.item.state !== "error") pendingCount++;
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
  function notify(id, immediate = false) {
    if (id)
      dirty.add(id);
    if (immediate) {
      if (timer)
        clearTimeout(timer);
      publish();
    } else if (!timer)
      timer = setTimeout(publish, interval);
  }
  function change(r, patch, immediate = false) {
    r.item = { ...r.item, ...patch };
    notify(r.item.id, immediate);
  }
  function prune() {
    const finished = ids.filter((id) => terminal.has(records.get(id).item.state));
    for (const id of finished.slice(0, Math.max(0, finished.length - retained))) {
      records.delete(id);
      dirty.add(id);
    }
    ids = ids.filter((id) => records.has(id));
  }
  function safePath(file) {
    const value = file.webkitRelativePath || file.name;
    if (value.length > 4096 || value.includes("\\") || value.startsWith("/") || value.split("/").some((s) => !s || s === "." || s === ".." || /[\x00-\x1f\x7f]/.test(s)))
      throw new Error("Unsafe folder upload path.");
    return value;
  }
  function policyFor(workspace) {
    let cached = policies.get(workspace);
    if (cached && cached.expires > Date.now())
      return cached.promise;
    cached?.controller.abort();
    const controller = new AbortController();
    const promise = options.transport.policy(workspace, controller.signal).catch((error) => {
      if (policies.get(workspace)?.controller === controller) policies.delete(workspace);
      throw error;
    });
    policies.set(workspace, { promise, expires: Date.now() + 6e4, controller });
    if (policies.size > 32) {
      const key = policies.keys().next().value;
      if (key && key !== workspace) {
        policies.get(key)?.controller.abort();
        policies.delete(key);
      }
    }
    return promise;
  }
  async function permit(workspace, limit, signal) {
    let scope = permits.get(workspace);
    if (!scope) {
      scope = { active: 0, waiters: [] };
      permits.set(workspace, scope);
    }
    while (scope.active >= limit) {
      await new Promise((resolve, reject) => {
        const wake = () => {
          signal.removeEventListener("abort", abort);
          resolve();
        };
        const abort = () => {
          scope.waiters = scope.waiters.filter((fn) => fn !== wake);
          reject(signal.reason ?? new DOMException("Cancelled", "AbortError"));
        };
        scope.waiters.push(wake);
        signal.addEventListener("abort", abort, { once: true });
      });
    }
    signal.throwIfAborted();
    scope.active++;
    return () => {
      scope.active--;
      const wake = scope.waiters.shift();
      if (wake)
        wake();
      else if (scope.active === 0)
        permits.delete(workspace);
    };
  }
  function schedule() {
    if (destroyed || paused)
      return;
    for (const id of ids) {
      if (active >= concurrency)
        break;
      const r = records.get(id);
      if (r.item.state !== "queued" || r.controller || r.retryAt > Date.now())
        continue;
      active++;
      const controller = new AbortController();
      r.controller = controller;
      const generation = ++r.generation;
      void run(r, controller.signal).catch((error) => {
        if (destroyed || r.generation !== generation || controller.signal.aborted)
          return;
        if (error instanceof TransferHttpError && error.status === 403) {
          policies.get(r.item.workspaceId)?.controller.abort();
          policies.delete(r.item.workspaceId);
        }
        if (r.attempts < retries && (error instanceof TransferHttpError && (error.status === 0 || error.status === 408 || error.status === 429 || error.status >= 500))) {
          r.attempts++;
          r.retryAt = Date.now() + Math.min(3e4, 500 * 2 ** (r.attempts - 1));
          change(r, { state: "queued", error: null });
        } else
          change(r, { state: "error", error: error instanceof Error ? error.message : "Upload failed." });
      }).finally(() => {
        r.controller = null;
        active--;
        prune();
        notify(r.item.id, true);
        schedule();
      });
    }
    if (retryTimer) {
      clearTimeout(retryTimer);
      retryTimer = null;
    }
    const next = ids.map((id) => records.get(id)).filter((r) => r.item.state === "queued" && r.retryAt > Date.now()).reduce((min, r) => Math.min(min, r.retryAt), Infinity);
    if (Number.isFinite(next))
      retryTimer = setTimeout(() => {
        retryTimer = null;
        schedule();
      }, Math.max(0, next - Date.now()));
    notify();
  }
  async function run(r, signal) {
    if (!r.file)
      return;
    const file = r.file;
    change(r, { state: "resolving", error: null });
    const policy = await policyFor(r.item.workspaceId);
    signal.throwIfAborted();
    if (!Number.isSafeInteger(policy.maxBytes) || !Number.isInteger(policy.maxConcurrent) || policy.maxConcurrent < 1 || !Number.isInteger(policy.maxParts) || policy.maxParts < 1)
      throw new Error("Invalid server upload policy.");
    if (r.item.relativePath.includes("/") && !r.folderResolved) {
      const path = r.item.relativePath.slice(0, r.item.relativePath.lastIndexOf("/"));
      const result = await options.transport.resolveFolders(r.item.workspaceId, r.item.parentId, [path], signal);
      const target = result.items.find((i) => i.path === path);
      if (!target)
        throw new Error("Folder destination could not be resolved.");
      change(r, { parentId: target.id });
      r.folderResolved = true;
    }
    const chunks = r.session !== null || policy.enabled && (file.size > policy.ordinaryMaxBytes || file.size <= policy.maxBytes && (file.size >= (options.chunkThresholdBytes ?? 8 * 1024 * 1024) || policy.multipartThresholdBytes != null && file.size >= policy.multipartThresholdBytes));
    if (chunks && r.session === null && file.size > policy.maxBytes)
      throw new Error("File exceeds the chunk upload size limit.");
    const release = await permit(r.item.workspaceId, Math.max(1, Math.min(concurrency, policy.maxConcurrent)), signal);
    try {
      change(r, { state: "uploading" });
      let entry;
      if (!chunks) {
        if (file.size > policy.ordinaryMaxBytes)
          throw new Error("Chunk uploads are disabled for this file size.");
        entry = await options.transport.ordinary(r.item.workspaceId, r.item.parentId, file, r.key, signal, (loaded) => {
          if (!signal.aborted) change(r, { loaded: Math.min(file.size, loaded) });
        });
      } else {
        r.session = r.session ? await options.transport.session(r.item.workspaceId, r.session.id, signal) : await options.transport.createSession(r.item.workspaceId, r.item.parentId, file, r.key, signal);
        const session = r.session;
        if (session.size !== file.size || session.chunkBytes < 65536 || session.chunkBytes > 33554432)
          throw new Error("Upload session does not match the selected file.");
        if (session.entry) {
          entry = session.entry;
        } else {
          const accepted = new Set(session.acceptedParts);
          let loaded = 0;
          const count = Math.ceil(file.size / session.chunkBytes);
          if (count > policy.maxParts)
            throw new Error("Too many upload parts.");
          for (let index = 0; index < count; index++) {
            const blob = file.slice(index * session.chunkBytes, Math.min(file.size, (index + 1) * session.chunkBytes));
            if (!accepted.has(index)) {
              await options.transport.part(r.item.workspaceId, session.id, index, blob, signal, (bytes) => {
                if (!signal.aborted) change(r, { loaded: Math.min(file.size, loaded + bytes) });
              }, session.transport);
              accepted.add(index);
              session.acceptedParts = [...accepted];
            }
            loaded += blob.size;
            change(r, { loaded });
          }
          change(r, { state: "finalizing", loaded: file.size });
          entry = await options.transport.complete(r.item.workspaceId, session.id, signal);
        }
      }
      signal.throwIfAborted();
      r.file = null;
      change(r, { state: "completed", loaded: file.size, entry }, true);
      try {
        options.onComplete?.(entry, r.item);
      } catch {
      }
    } finally {
      release();
    }
  }
  function control(id, state) {
    const r = records.get(id);
    if (!r || terminal.has(r.item.state) || r.item.state === "finalizing")
      return;
    r.generation++;
    r.controller?.abort();
    change(r, { state, error: null }, true);
    if (state === "cancelled") {
      r.file = null;
      if (r.session) {
        const controller = new AbortController();
        auxiliary.add(controller);
        void options.transport.cancel(r.item.workspaceId, r.session.id, controller.signal).catch(() => {
        }).finally(() => auxiliary.delete(controller));
      }
    }
  }
  return {
    invalidatePolicy(workspace) {
      policies.delete(workspace);
    },
    enqueue(files, context) {
      if (destroyed)
        throw new Error("Transfer queue is destroyed.");
      const admitted = [];
      for (const file of files) {
        if (records.size + admitted.length >= maxFiles)
          throw new Error("Upload queue is full.");
        safePath(file);
        admitted.push(file);
      }
      const result = [];
      for (const file of admitted) {
        const id = randomId();
        records.set(id, { item: { id, name: file.name, relativePath: safePath(file), workspaceId: context.workspaceId, parentId: context.parentId, size: file.size, loaded: 0, state: paused ? "paused" : "queued", error: null }, file, controller: null, session: null, key: randomId(), generation: 0, attempts: 0, folderResolved: false, retryAt: 0 });
        result.push(id);
      }
      ids = [...ids, ...result];
      notify(void 0, true);
      schedule();
      return result;
    },
    subscribe(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
    getSnapshot: () => snapshot,
    subscribeItem(id, fn) {
      let set = itemListeners.get(id);
      if (!set) {
        set = /* @__PURE__ */ new Set();
        itemListeners.set(id, set);
      }
      set.add(fn);
      return () => {
        set.delete(fn);
        if (set.size === 0)
          itemListeners.delete(id);
      };
    },
    getItem: (id) => records.get(id)?.item ?? null,
    pauseAll() {
      if (destroyed || paused) return;
      paused = true;
      if (retryTimer) {
        clearTimeout(retryTimer);
        retryTimer = null;
      }
      for (const r of records.values()) {
        if (!["queued", "resolving", "uploading"].includes(r.item.state)) continue;
        r.generation++;
        r.controller?.abort();
        r.item = { ...r.item, state: "paused", error: null };
        dirty.add(r.item.id);
      }
      notify(void 0, true);
    },
    resumeAll() {
      if (destroyed) return;
      paused = false;
      for (const r of records.values()) {
        if (r.item.state !== "paused") continue;
        r.retryAt = 0;
        r.item = { ...r.item, state: "queued" };
        dirty.add(r.item.id);
      }
      notify(void 0, true);
      schedule();
    },
    pause: (id) => control(id, "paused"),
    cancel: (id) => control(id, "cancelled"),
    resume(id) {
      const r = records.get(id);
      if (!paused && r && r.item.state === "paused") {
        change(r, { state: "queued" }, true);
        schedule();
      }
    },
    retry(id) {
      const r = records.get(id);
      if (r && r.item.state === "error") {
        r.attempts = 0;
        r.retryAt = 0;
        change(r, { state: paused ? "paused" : "queued", error: null }, true);
        schedule();
      }
    },
    clearFinished() {
      for (const id of ids)
        if (terminal.has(records.get(id).item.state)) {
          records.delete(id);
          dirty.add(id);
        }
      ids = ids.filter((id) => records.has(id));
      notify(void 0, true);
    },
    destroy() {
      destroyed = true;
      if (timer)
        clearTimeout(timer);
      if (retryTimer)
        clearTimeout(retryTimer);
      for (const controller of auxiliary)
        controller.abort();
      auxiliary.clear();
      for (const entry of policies.values())
        entry.controller.abort();
      policies.clear();
      for (const r of records.values()) {
        r.controller?.abort();
        r.file = null;
      }
      records.clear();
      ids = [];
      listeners.clear();
      itemListeners.clear();
      dirty.clear();
    }
  };
}
export {
  TransferHttpError,
  createTransferQueue,
  createUploadTransport
};
