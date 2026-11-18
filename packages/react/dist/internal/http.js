// src/tree-operations.ts
async function finishTreeOperation(value, advance, signal, notify) {
  if (!value || typeof value !== "object" || !("treeOperation" in value)) return value;
  let result = value;
  while (true) {
    signal.throwIfAborted();
    notify?.(result.treeOperation);
    if (result.treeOperation.state === "completed") return result;
    if (result.treeOperation.state !== "preparing") throw new Error(result.treeOperation.error ?? "The folder operation was cancelled.");
    await new Promise((resolve, reject) => {
      const stop = () => {
        clearTimeout(timer);
        reject(signal.reason);
      };
      const timer = setTimeout(() => {
        signal.removeEventListener("abort", stop);
        resolve();
      }, 50);
      signal.addEventListener("abort", stop, { once: true });
    });
    result = await advance(result.treeOperation.id);
  }
}

// src/random-id.ts
function randomId() {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = bytes[6] & 15 | 64;
  bytes[8] = bytes[8] & 63 | 128;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join("-");
}

// src/assistant/http.ts
function createAssistantTransport(request, options) {
  const path = (workspace, conversation) => `/workspaces/${encodeURIComponent(workspace)}/assistant/conversations${conversation ? "/" + encodeURIComponent(conversation) : ""}`;
  return {
    undo: (w, id, s) => request(path(w).replace(/conversations$/, "actions/") + encodeURIComponent(id) + "/undo", s, void 0, "POST"),
    rename: (w, id, title, revision, s) => request(path(w, id), s, JSON.stringify({ title, revision }), "PATCH"),
    remove: (w, id, revision, s) => request(path(w, id), s, JSON.stringify({ revision }), "DELETE"),
    restore: (w, id, revision, s) => request(path(w, id) + "/restore", s, JSON.stringify({ revision })),
    discardAttachment: (w, id, s) => request(path(w).replace(/conversations$/, "attachments/") + encodeURIComponent(id), s, void 0, "DELETE"),
    upload: (w, file, s) => {
      const body = new FormData();
      body.append("file", file);
      return request(path(w).replace(/conversations$/, "attachments"), s, body);
    },
    profile: (w, s) => request(path(w).replace(/conversations$/, "profile"), s),
    saveProfile: (w, p, s) => request(path(w).replace(/conversations$/, "profile"), s, JSON.stringify(p), "PUT"),
    list: (w, c, s, search) => request(path(w) + "?" + new URLSearchParams({ ...c ? { cursor: c } : {}, ...search ? { search } : {} }), s),
    create: (w, s) => request(path(w), s, void 0, "POST"),
    show: (w, c, s, page) => request(path(w, c) + (page ? "?" + new URLSearchParams(page) : ""), s),
    send: (w, c, input, s) => request(path(w, c) + "/messages", s, JSON.stringify(input)),
    cancel: (w, c, s) => request(path(w, c) + "/cancel", s, void 0, "POST"),
    events: async (w, c, r, after, onEvent, signal) => {
      const response = await fetch(options.baseUrl.replace(/\/$/, "") + path(w, c) + `/runs/${encodeURIComponent(r)}/events?after=${after}`, { signal, credentials: options.credentials ?? "same-origin", headers: { Accept: "text/event-stream" } });
      if (!response.ok || !response.body) throw new Error("The response stream is unavailable.");
      const reader = response.body.getReader(), decoder = new TextDecoder();
      let pending = "";
      try {
        while (true) {
          const part = await reader.read();
          if (part.done) break;
          pending += decoder.decode(part.value, { stream: true }).replace(/\r\n/g, "\n");
          if (pending.length > 131072) throw new Error("Invalid response stream.");
          let boundary;
          while ((boundary = pending.indexOf("\n\n")) >= 0) {
            const block = pending.slice(0, boundary);
            pending = pending.slice(boundary + 2);
            let id = 0, type = "", data = "";
            for (const line of block.split("\n")) {
              if (line.startsWith("id:")) id = Number(line.slice(3));
              if (line.startsWith("event:")) type = line.slice(6).trim();
              if (line.startsWith("data:")) data += line.slice(5).trimStart();
            }
            if (id > after && Number.isSafeInteger(id) && data) {
              const event = { id, type, data: JSON.parse(data) };
              onEvent(event);
              if (type === "done") {
                await reader.cancel();
                return;
              }
            }
          }
        }
      } finally {
        reader.releaseLock();
      }
    }
  };
}

// src/management-http.ts
function createManagementTransport(options) {
  const base = options.baseUrl.replace(/\/$/, "");
  if (!/^https?:\/\//.test(base) && !base.startsWith("/")) throw new Error("An HTTP(S) or root-relative API URL is required.");
  const path = (workspace, suffix) => "/workspaces/" + encodeURIComponent(workspace) + "/" + suffix;
  async function request(url, signal, body, method) {
    const headers = new Headers({ Accept: "application/json" });
    if (body !== void 0) headers.set("Content-Type", "application/json");
    if (method && options.csrfToken) headers.set("X-CSRF-TOKEN", options.csrfToken());
    const response = await fetch(base + url, {
      signal,
      headers,
      credentials: options.credentials ?? "same-origin",
      method: method ?? "GET",
      ...body === void 0 ? {} : { body: JSON.stringify(body) }
    });
    const data = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
      const message = data && typeof data === "object" && "message" in data && typeof data.message === "string" ? data.message : "The request could not be completed.";
      throw new StudioHttpError(response.status, message);
    }
    return data;
  }
  function query(values, cursor) {
    const params = new URLSearchParams({ limit: "60" });
    for (const [key, value] of Object.entries(values)) {
      if (value !== void 0) params.set(key, value === true ? "1" : value === false ? "0" : value);
    }
    if (cursor) params.set("cursor", cursor);
    return "?" + params;
  }
  async function page(url, signal) {
    const result = await request(url, signal);
    if (!result || !Array.isArray(result.items) || result.items.length > 60 || result.nextCursor !== null && typeof result.nextCursor !== "string" || result.previousCursor != null && typeof result.previousCursor !== "string" || typeof result.hasMore !== "boolean") throw new Error("Unsupported management response.");
    return result;
  }
  return {
    browserPush: {
      workerUrl: base + "/push/worker.js",
      status: (workspace, device, signal) => request(path(workspace, "notification-push") + (device ? "?device=" + encodeURIComponent(device) : ""), signal),
      preferences: (workspace, events, signal) => request(path(workspace, "notification-push/preferences"), signal, { events }, "PUT"),
      subscribe: (workspace, subscription, returnPath, locale, signal) => request(path(workspace, "notification-push/devices"), signal, { ...subscription, path: returnPath, locale }, "POST"),
      unsubscribe: (workspace, device, signal) => request(path(workspace, "notification-push/devices/" + encodeURIComponent(device)), signal, void 0, "DELETE")
    },
    inviteShare: (workspace, input, signal) => request(path(workspace, "shares/invitations"), signal, input, "POST"),
    shareLink: (workspace, id, signal) => request(path(workspace, "shares/" + encodeURIComponent(id) + "/link"), signal),
    shareAnalytics: (workspace, id, days, signal) => request(path(workspace, "shares/" + encodeURIComponent(id) + "/analytics?days=" + days), signal),
    deleteShare: (workspace, id, revision, signal) => request(path(workspace, "shares/" + encodeURIComponent(id)), signal, { revision }, "DELETE"),
    shares: (workspace, values, cursor, signal) => page(path(workspace, "shares") + query({ ...values }, cursor), signal),
    createShare: (workspace, input, signal) => request(path(workspace, "shares"), signal, input, "POST"),
    updateShare: (workspace, id, input, signal) => request(path(workspace, "shares/" + encodeURIComponent(id)), signal, input, "PATCH"),
    rotateShare: (workspace, id, revision, signal) => request(path(workspace, "shares/" + encodeURIComponent(id) + "/rotate"), signal, { revision }, "POST"),
    trashReport: (workspace, values, cursor, signal) => page(path(workspace, "trash-report") + query({ ...values }, cursor), signal),
    restoreEntry: async (workspace, id, revision, signal) => {
      const initial = await request(path(workspace, "entries/" + encodeURIComponent(id) + "/restore"), signal, { revision }, "POST");
      const result = await finishTreeOperation(initial, (operation) => request(path(workspace, "tree-operations/" + encodeURIComponent(operation) + "/advance"), signal, {}, "POST"), signal);
      return "treeOperation" in result ? result.items[0] : result;
    },
    readVisibleNotifications: (workspace, ids, signal) => request(path(workspace, "notifications/read-visible"), signal, { ids }, "POST"),
    notifications: (workspace, unread, cursor, signal) => page(path(workspace, "notifications") + query({ unread }, cursor), signal),
    readNotification: (workspace, id, read, signal) => request(path(workspace, "notifications/" + encodeURIComponent(id)), signal, { read }, "PATCH"),
    readAllNotifications: (workspace, signal) => request(path(workspace, "notifications/read-all"), signal, {}, "POST"),
    tags: (workspace, search, cursor, signal) => page(path(workspace, "tags") + query({ search }, cursor), signal)
  };
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

// src/http.ts
var StudioHttpError = class extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
    this.name = "StudioHttpError";
  }
  status;
};
function createHttpTransport(options) {
  const base = options.baseUrl.replace(/\/$/, "");
  if (!/^https?:\/\//.test(base) && !base.startsWith("/")) throw new Error("An HTTP(S) or root-relative API URL is required.");
  const navigationListeners = /* @__PURE__ */ new Map();
  let channel = null;
  function signalNavigation(workspace) {
    for (const listener of navigationListeners.get(workspace) ?? []) listener();
  }
  function subscribeNavigation(workspace, listener) {
    let listeners = navigationListeners.get(workspace);
    if (!listeners) {
      listeners = /* @__PURE__ */ new Set();
      navigationListeners.set(workspace, listeners);
    }
    listeners.add(listener);
    if (!channel && typeof BroadcastChannel !== "undefined") {
      channel = new BroadcastChannel("fms.navigation." + base);
      channel.onmessage = (event) => {
        if (typeof event.data === "string") signalNavigation(event.data);
      };
    }
    const unsubscribeHost = options.navigationSubscribe?.(workspace, listener);
    return () => {
      unsubscribeHost?.();
      listeners.delete(listener);
      if (!listeners.size) navigationListeners.delete(workspace);
      if (!navigationListeners.size) {
        channel?.close();
        channel = null;
      }
    };
  }
  async function request(path2, signal, body, method) {
    const headers = new Headers({ Accept: "application/json" });
    if (typeof body === "string") headers.set("Content-Type", "application/json");
    if ((body !== void 0 || method !== void 0) && options.csrfToken) headers.set("X-CSRF-TOKEN", options.csrfToken());
    const response = await fetch(base + path2, { signal, headers, credentials: options.credentials ?? "same-origin", method: method ?? (body === void 0 ? "GET" : "POST"), ...body === void 0 ? {} : { body } });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
      const message = data && typeof data === "object" && "message" in data && typeof data.message === "string" ? data.message : "The request could not be completed.";
      throw new StudioHttpError(response.status, message);
    }
    if (body !== void 0 || method !== void 0) {
      const workspace = /^\/workspaces\/([^/]+)/.exec(path2)?.[1];
      if (workspace) {
        signalNavigation(decodeURIComponent(workspace));
        channel?.postMessage(decodeURIComponent(workspace));
      }
    }
    return data;
  }
  function path(workspace, suffix) {
    return `/workspaces/${encodeURIComponent(workspace)}/${suffix}`;
  }
  async function mutation(workspace, url, signal, body) {
    const initial = await request(url, signal, body);
    const result = await finishTreeOperation(initial, (id) => request(path(workspace, `tree-operations/${encodeURIComponent(id)}/advance`), signal, "{}"), signal, options.onTreeOperation);
    if (result && typeof result === "object" && "treeOperation" in result && !url.endsWith("/bulk")) return result.items[0];
    return result;
  }
  function browseParams(query) {
    const params = new URLSearchParams({ view: query.view, search: query.search });
    if (query.limit !== void 0) params.set("limit", String(Math.max(1, Math.min(120, query.limit))));
    if (query.parent) params.set("parent", query.parent);
    if (query.sort) params.set("sort", query.sort);
    if (query.direction) params.set("direction", query.direction);
    if (query.thumbnailPreset) params.set("thumbnailPreset", query.thumbnailPreset);
    for (const [key, value] of Object.entries(query.photos ?? {})) if (value !== void 0) params.set(`photos[${key}]`, value);
    for (const [key, value] of Object.entries(query.filters ?? {})) {
      if (Array.isArray(value)) value.forEach((item, index) => params.set(`filters[${key}][${index}]`, String(item)));
      else if (value !== void 0) params.set(`filters[${key}]`, String(value));
    }
    for (const [key, value] of Object.entries(query.customFilters ?? {})) params.set(`customFilters[${key}]`, value);
    return params;
  }
  return {
    treeOperations: {
      status: (workspace, id, signal) => request(path(workspace, `tree-operations/${encodeURIComponent(id)}`), signal),
      advance: (workspace, id, signal) => request(path(workspace, `tree-operations/${encodeURIComponent(id)}/advance`), signal, "{}"),
      cancel: (workspace, id, signal) => request(path(workspace, `tree-operations/${encodeURIComponent(id)}/cancel`), signal, "{}")
    },
    folders: {
      subscribe: subscribeNavigation,
      changes: (workspace, after, signal) => request(path(workspace, "navigation/changes") + "?" + new URLSearchParams({ after: String(after) }), signal),
      undo: (workspace, operation, signal) => request(path(workspace, `navigation/operations/${encodeURIComponent(operation)}/undo`), signal, "{}"),
      context: (workspace, folder, signal) => request(path(workspace, `folders/${encodeURIComponent(folder)}/context`), signal),
      ancestors: (workspace, folder, cursor, limit, signal) => request(path(workspace, `folders/${encodeURIComponent(folder)}/ancestors`) + "?" + new URLSearchParams({ cursor, limit: String(Math.max(1, Math.min(100, limit))) }), signal)
    },
    assistant: createAssistantTransport(request, options),
    supportsBrowseControls: true,
    librarySearch: (workspace, query, cursor, signal) => {
      const params = browseParams({ view: "files", parent: null, search: "", ...query.filters ? { filters: query.filters } : {}, ...query.customFilters ? { customFilters: query.customFilters } : {} });
      params.delete("view");
      params.delete("search");
      params.set("q", query.text);
      if (query.mode) params.set("mode", query.mode);
      if (query.kind) params.set("kind", query.kind);
      if (query.limit) params.set("limit", String(query.limit));
      if (cursor) params.set("cursor", cursor);
      return request(path(workspace, "library-search") + "?" + params, signal);
    },
    integrations: {
      approveExtension: (workspace, input, signal) => request(path(workspace, "integrations/browser-extension/approve"), signal, JSON.stringify(input)),
      overview: (workspace, signal) => request(path(workspace, "integrations"), signal),
      startSlackOAuth: (workspace, input, signal) => request(path(workspace, "integrations/slack/oauth"), signal, JSON.stringify(input)),
      saveSlack: (workspace, input, signal) => request(path(workspace, "integrations/slack"), signal, JSON.stringify(input), "PUT"),
      disconnectSlack: (workspace, revision, signal) => request(path(workspace, "integrations/slack"), signal, JSON.stringify({ revision }), "DELETE")
    },
    apiAccess: {
      overview: (workspace, signal) => request(path(workspace, "api-access"), signal),
      create: (workspace, input, signal) => request(path(workspace, "api-access/tokens"), signal, JSON.stringify(input)),
      revoke: (workspace, token, signal) => request(path(workspace, `api-access/tokens/${encodeURIComponent(token)}`), signal, void 0, "DELETE")
    },
    aiInfo: (signal) => request("/ai", signal),
    aiLabels: {
      list: (workspace, state, cursor, signal) => request(path(workspace, "ai/labels") + "?" + new URLSearchParams({ state, ...cursor ? { cursor } : {} }), signal),
      review: (workspace, id, state, revision, signal) => request(path(workspace, "ai/labels/" + encodeURIComponent(id)), signal, JSON.stringify({ state, revision }), "PATCH")
    },
    searchIndex: (workspace, q, mode, cursor, signal) => request(path(workspace, "search") + "?" + new URLSearchParams({ q, mode, ...cursor ? { cursor } : {} }), signal),
    applicationFiles: {
      sources: (signal) => request("/application-files", signal),
      browse: (source, query, cursor, signal) => {
        const params = new URLSearchParams({ limit: "60", search: query.search });
        if (query.parent) params.set("parent", query.parent);
        if (cursor) params.set("cursor", cursor);
        if (source) params.set("source", source);
        return request("/application-files/catalog?" + params, signal);
      },
      thumbnailStatus: (ids, signal) => request("/application-files/catalog/thumbnail-status", signal, JSON.stringify({ ids })),
      details: (source, entry, signal) => request("/application-files/catalog/entries/" + encodeURIComponent(entry), signal),
      neighbors: (source, entry, query, direction, signal, cursor) => {
        const params = new URLSearchParams({ direction, search: query.search });
        if (cursor) params.set("cursor", cursor);
        if (source) params.set("source", source);
        return request("/application-files/catalog/entries/" + encodeURIComponent(entry) + "/neighbors?" + params, signal);
      },
      contentUrl: (source, entry, download = false) => base + "/application-files/catalog/entries/" + encodeURIComponent(entry) + "/original" + (download ? "?download=1" : "")
    },
    comments: {
      list: (w, e, filter, cursor, signal, options2) => request(path(w, `entries/${encodeURIComponent(e)}/comments`) + `?filter=${filter}` + (cursor ? "&cursor=" + encodeURIComponent(cursor) : "") + (options2?.anchorVersion ? "&anchorVersion=" + encodeURIComponent(options2.anchorVersion) : ""), signal),
      create: (w, e, input, signal) => request(path(w, `entries/${encodeURIComponent(e)}/comments`), signal, JSON.stringify({ ...input, ...input.document ? { document: JSON.stringify(input.document) } : {} })),
      update: (w, e, id, input, signal) => request(path(w, `entries/${encodeURIComponent(e)}/comments/${encodeURIComponent(id)}`), signal, JSON.stringify({ ...input, ...input.document ? { document: JSON.stringify(input.document) } : {} }), "PATCH"),
      mentions: (w, e, search, signal) => request(path(w, `entries/${encodeURIComponent(e)}/comment-mentions?search=${encodeURIComponent(search)}`), signal)
    },
    note: (workspace, entry, signal) => request(path(workspace, `notes/${encodeURIComponent(entry)}`), signal),
    createNote: (workspace, input, signal) => request(path(workspace, "notes"), signal, JSON.stringify(input)),
    updateNote: (workspace, entry, input, signal) => request(path(workspace, `notes/${encodeURIComponent(entry)}`), signal, JSON.stringify(input), "PATCH"),
    workspaceCreationCandidates: (workspace, search, signal) => request(path(workspace, "workspace-creation-candidates") + "?search=" + encodeURIComponent(search), signal),
    memberCandidates: (workspace, search, signal) => request(path(workspace, "member-candidates") + "?search=" + encodeURIComponent(search), signal),
    assignMember: (workspace, value, signal) => request(path(workspace, "members"), signal, JSON.stringify(value)),
    updateMember: (workspace, id, role, signal) => request(path(workspace, `members/${encodeURIComponent(id)}`), signal, JSON.stringify({ role }), "PATCH"),
    inviteMember: (workspace, value, signal) => request(path(workspace, "invitations"), signal, JSON.stringify(value)),
    invitations: (workspace, cursor, signal) => request(path(workspace, "invitations") + (cursor ? "?after=" + encodeURIComponent(cursor) : ""), signal),
    revokeInvitation: (workspace, id, signal) => request(path(workspace, `invitations/${encodeURIComponent(id)}/revoke`), signal, void 0, "POST"),
    members: (workspace, cursor, signal, search) => {
      const params = new URLSearchParams();
      if (cursor) params.set("cursor", cursor);
      if (search) params.set("search", search);
      return request(path(workspace, "members") + (params.size ? "?" + params : ""), signal);
    },
    removeMember: (workspace, member, signal) => request(path(workspace, `members/${encodeURIComponent(member)}/remove`), signal, void 0, "POST"),
    sourceImports: (workspace, signal) => request(path(workspace, "source-imports"), signal),
    startSourceImport: (workspace, mount, signal) => request(path(workspace, "source-imports"), signal, JSON.stringify({ mount }), "POST"),
    stepSourceImport: (workspace, run, revision, signal) => request(path(workspace, `source-imports/${encodeURIComponent(run)}/step`), signal, JSON.stringify({ revision }), "POST"),
    controlSourceImport: (workspace, run, revision, action, signal) => request(path(workspace, `source-imports/${encodeURIComponent(run)}`), signal, JSON.stringify({ revision, action }), "PATCH"),
    management: createManagementTransport(options),
    videoEdit: (workspace, entry, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}/video-edit`), signal),
    trimVideo: (workspace, entry, input, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}/video-edit`), signal, JSON.stringify(input)),
    optimize: (workspace, entry, revision, signal, options2) => request(path(workspace, `entries/${encodeURIComponent(entry)}/optimize`), signal, JSON.stringify({ revision, ...options2 })),
    settings: (workspace, signal) => request(path(workspace, "settings"), signal),
    updateSettings: (workspace, value, signal) => request(path(workspace, "settings"), signal, JSON.stringify(value), "PATCH"),
    browseChoices: async (workspace, kind, values, signal) => {
      const params = new URLSearchParams({ kind, limit: "60" });
      if (values.search) params.set("search", values.search);
      if (values.cursor) params.set("cursor", values.cursor);
      if (values.locale) params.set("locale", values.locale);
      const result = await request(path(workspace, "filter-options") + "?" + params, signal);
      if (!Array.isArray(result.items) || result.items.length > 60 || !result.items.every((item) => typeof item.id === "string" && typeof item.label === "string") || result.nextCursor !== null && typeof result.nextCursor !== "string" || typeof result.hasMore !== "boolean") throw new Error("Unsupported filter options response.");
      return result;
    },
    transfers: createUploadTransport(options),
    neighbors: (workspace, entry, query, direction, signal, cursor) => {
      const params = browseParams({ ...query, view: query.view === "trash" || query.view === "folders" ? "files" : query.view });
      params.set("direction", direction);
      if (query.direction) params.set("sortDirection", query.direction);
      if (cursor) params.set("cursor", cursor);
      return request(path(workspace, `entries/${encodeURIComponent(entry)}/neighbors`) + "?" + params, signal);
    },
    star: (workspace, entry, starred, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}/star`), signal, JSON.stringify({ starred }), "PUT"),
    visit: (workspace, entry, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}/visit`), signal, void 0, "POST"),
    bulk: (workspace, values, signal) => mutation(workspace, path(workspace, "entries/bulk"), signal, JSON.stringify({ ...values, requestId: values.requestId ?? randomId() })),
    status: (workspace, ids, signal, options2) => request(path(workspace, "entries/status"), signal, JSON.stringify({ ids, ...options2?.thumbnailPreset ? { thumbnailPreset: options2.thumbnailPreset } : {} })),
    details: (workspace, entry, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}`), signal),
    replaceTags: (workspace, entry, revision, tags, signal) => request(
      path(workspace, `entries/${encodeURIComponent(entry)}/tags`),
      signal,
      JSON.stringify({ revision, tags }),
      "PATCH"
    ),
    update: (workspace, entry, values, signal) => request(path(workspace, `entries/${encodeURIComponent(entry)}`), signal, JSON.stringify(Object.keys(values).every((key) => ["requestId", "revision", "name", "parent"].includes(key)) ? { ...values, requestId: values.requestId ?? randomId() } : values), "PATCH"),
    trash: (workspace, entry, revision, signal) => mutation(workspace, path(workspace, `entries/${encodeURIComponent(entry)}/trash`), signal, JSON.stringify({ revision, requestId: randomId() })),
    restore: (workspace, entry, revision, signal) => mutation(workspace, path(workspace, `entries/${encodeURIComponent(entry)}/restore`), signal, JSON.stringify({ revision, requestId: randomId() })),
    workspace: (workspace, signal) => request(`/workspaces/${encodeURIComponent(workspace)}`, signal),
    workspaces: (signal) => request("/workspaces", signal),
    retireWorkspace: (workspace, input, signal) => request(`/workspaces/${encodeURIComponent(workspace)}/retirement`, signal, JSON.stringify(input), "POST"),
    workspaceOperation: (id, signal) => request(`/workspace-operations/${encodeURIComponent(id)}`, signal),
    retryWorkspaceOperation: (id, signal) => request(`/workspace-operations/${encodeURIComponent(id)}/retry`, signal, "{}", "POST"),
    deleteWorkspace: (workspace, confirmName, signal) => request(`/workspaces/${encodeURIComponent(workspace)}`, signal, JSON.stringify({ confirmName }), "DELETE"),
    updateWorkspace: (workspace, name, signal, identity) => request(`/workspaces/${encodeURIComponent(workspace)}`, signal, JSON.stringify({ name, ...identity }), "PATCH"),
    makeDefaultWorkspace: (workspace, signal) => request(path(workspace, "make-default"), signal, JSON.stringify({})),
    createWorkspace: (name, signal, identity) => request("/workspaces", signal, JSON.stringify({ name, ...identity })),
    browse: async (workspace, query, cursor, signal) => {
      const params = browseParams(query);
      if (cursor) params.set("cursor", cursor);
      const result = await request(path(workspace, "entries") + "?" + params, signal);
      if (!Array.isArray(result.items) || result.items.length > 120 || !result.items.every((e) => typeof e.id === "string" && typeof e.name === "string" && (e.kind === "file" || e.kind === "folder")) || result.nextCursor !== null && typeof result.nextCursor !== "string" || result.previousCursor !== null && typeof result.previousCursor !== "string") throw new Error("Unsupported listing response.");
      return result;
    },
    createFolder: (workspace, parent, name, signal) => request(path(workspace, "folders"), signal, JSON.stringify({ parent, name })),
    upload: async (workspace, parent, file, key, signal) => {
      const body = new FormData();
      body.append("file", file);
      if (parent) body.append("parent", parent);
      const headers = new Headers({ Accept: "application/json", "Idempotency-Key": key });
      if (options.csrfToken) headers.set("X-CSRF-TOKEN", options.csrfToken());
      const response = await fetch(base + path(workspace, "uploads"), { method: "POST", body, signal, headers, credentials: options.credentials ?? "same-origin" });
      const data = await response.json().catch(() => null);
      if (!response.ok) throw new StudioHttpError(response.status, data && typeof data === "object" && "message" in data && typeof data.message === "string" ? data.message : "Upload failed.");
      return data;
    },
    archiveUrl: (workspace, entries) => {
      const params = new URLSearchParams();
      entries.forEach((id, index) => params.set(`entries[${index}]`, id));
      return base + path(workspace, "download") + "?" + params;
    },
    createArchive: (workspace, entries, signal) => request(path(workspace, "archive-jobs"), signal, JSON.stringify({ entries })),
    archiveJobs: (workspace, signal) => request(path(workspace, "archive-jobs"), signal),
    archiveResultUrl: (workspace, job) => base + path(workspace, `archive-jobs/${encodeURIComponent(job)}/result`),
    contentUrl: (workspace, entry, download = false, versionId) => {
      const version = versionId ? `/versions/${encodeURIComponent(versionId)}` : "";
      return base + path(workspace, `entries/${encodeURIComponent(entry)}${version}/content`) + (download ? "?download=1" : "");
    }
  };
}
export {
  StudioHttpError,
  createHttpTransport
};
