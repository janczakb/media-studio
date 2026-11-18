import type { Entry, EntryPage, StudioTransport } from '../contracts';
export type FolderSummary = Pick<Entry, 'id' | 'name' | 'hasChildFolders' | 'readOnly'>;
type FolderPage = {
    key: number;
    cursor: string | null;
    items: FolderSummary[] | null;
    count: number;
    next: string | null;
    previous: string | null;
    loaded: boolean;
    stale?: boolean;
    error: string;
    used: number;
    retained?: Map<number,FolderSummary>;
};
type Branch = {
    entry: FolderSummary | null;
    parent: string | null;
    pages: FolderPage[];
    used: number;
    canWrite: boolean;
};
export type FolderTreeRow = {
    key: string;
    depth: number;
    parent: string | null;
    folder: FolderSummary | null;
    page: number;
    cursor: string | null;
    edge: 'next' | 'previous' | null;
    error: string;
};
export interface FolderTreeSnapshot {
    root: FolderSummary | null;
    canWrite: boolean;
    rows: readonly FolderTreeRow[];
    expanded: ReadonlySet<string | null>;
    loading: ReadonlySet<string>;
    version: number;
    restoring: boolean;
}
export interface FolderTreeOptions {
    pageSize?: number;
    /** Idle response pages. Expanded outlines remain resident within branch/window limits. */
    cachedPages?: number;
    concurrency?: number;
}
const MAX_BRANCHES = 96, MAX_PAGES = 4, CACHE_PAGES = 12, MAX_EXPANDED = 64;
/** Per-instance, per-workspace folder cache. Cursor windows also bound metadata. */
export class FolderTreeClient {
    private branches = new Map<string | null, Branch>();
    private expanded = new Set<string | null>();
    private requests = new Map<string, {
        controller: AbortController;
        parent: string | null;
    }>();
    private listeners = new Set<() => void>();
    private visible = new Set<string>();
    private visibleFolders = new Set<string>();
    private path: FolderSummary[] = [];
    private root: FolderSummary | null = null;
    private clock = 0;
    private sequence = 0;
    private snapshot: FolderTreeSnapshot = { root: null, canWrite: false, rows: [], expanded: new Set(), loading: new Set(), version: 0, restoring: false };
    private suspended = false;
    private restoring = false;
    private pageSize: number;
    private cachedPages: number;
    private concurrency: number;
    constructor(readonly workspace: string, private browse: StudioTransport['browse'], options: FolderTreeOptions = {}) {
        const bounded = (value: number | undefined, fallback: number, minimum: number, maximum: number) => Number.isFinite(value) ? Math.max(minimum, Math.min(maximum, Math.floor(value!))) : fallback;
        this.pageSize = bounded(options.pageSize, 50, 10, 100);
        this.cachedPages = bounded(options.cachedPages, CACHE_PAGES, 4, Math.min(32, Math.floor(1800 / this.pageSize)));
        this.concurrency = bounded(options.concurrency, 2, 1, 4);
    }
    subscribe = (listener: () => void) => { this.listeners.add(listener); return () => { this.listeners.delete(listener); }; };
    getSnapshot = () => this.snapshot;
    private branch(parent: string | null): Branch {
        let branch = this.branches.get(parent);
        if (!branch) {
            branch = { entry: null, parent: null, pages: [this.page(null)], canWrite: false, used: ++this.clock };
            this.branches.set(parent, branch);
        }
        branch.used = ++this.clock;
        return branch;
    }
    private page(cursor: string | null): FolderPage { return { key: ++this.sequence, cursor, items: null, count: 1, next: null, previous: null, loaded: false, error: '', used: ++this.clock }; }
    private requestKey(parent: string | null, page: number) { return JSON.stringify([parent, page]); }
    private publish() {
        const rows: FolderTreeRow[] = [];
        type Task = {parent: string | null; depth: number} | {row: FolderTreeRow};
        const tasks: Task[] = [{parent: this.root?.id ?? null, depth: 1}];
        const visited = new Set<string>();
        while (tasks.length) {
            const task = tasks.pop()!;
            if ('row' in task) {
                if (task.row.folder) {
                    if (visited.has(task.row.folder.id)) continue;
                    visited.add(task.row.folder.id);
                    if (this.expanded.has(task.row.folder.id) || this.branches.has(task.row.folder.id)) {
                        const child = this.branch(task.row.folder.id);
                        child.entry = task.row.folder;
                        child.parent = task.row.parent;
                    }
                }
                rows.push(task.row);
                if (task.row.folder && this.expanded.has(task.row.folder.id)) tasks.push({parent: task.row.folder.id, depth: task.row.depth + 1});
                continue;
            }
            const {parent, depth} = task;
            if (!this.expanded.has(parent)) continue;
            const branch = this.branch(parent);
            const children: FolderTreeRow[] = [];
            const append = (folder: FolderSummary, page: number, cursor: string | null) => {
                children.push({key: folder.id, depth, parent, folder, page, cursor, edge: null, error: ''});
            };
            const pinned = this.root ? undefined : this.path.find((folder, index) => (index === 0 ? null : this.path[index - 1]!.id) === parent);
            const present = new Set(branch.pages.flatMap(page => page.items?.map(folder => folder.id) ?? [...(page.retained?.values() ?? [])].map(folder => folder.id)));
            const pinnedOnly = pinned && !present.has(pinned.id);
            if (pinnedOnly) append(pinned, branch.pages[0]?.key ?? 0, branch.pages[0]?.cursor ?? null);
            const first = branch.pages[0];
            if (first?.previous) children.push({key: `previous:${parent}`, depth, parent, folder: null, page: first.key, cursor: first.previous, edge: 'previous', error: ''});
            const seen = new Set<string>();
            for (const page of branch.pages) {
                if (page.items) {
                    for (const folder of page.items) if (!seen.has(folder.id)) {
                        seen.add(folder.id);
                        append(folder, page.key, page.cursor);
                    }
                } else {
                    if (pinnedOnly && !page.loaded && page.cursor === null) continue;
                    for (let offset = 0; offset < page.count; offset++) {
                        const retained = page.retained?.get(offset);
                        if (retained && !seen.has(retained.id)) {
                            seen.add(retained.id);
                            append(retained, page.key, page.cursor);
                        } else children.push({key: `page:${parent}:${page.key}:${offset}`, depth, parent, folder: null, page: page.key, cursor: page.cursor, edge: null, error: page.error});
                    }
                }
            }
            const last = branch.pages.at(-1);
            if (last?.loaded && last.next) children.push({key: `next:${parent}`, depth, parent, folder: null, page: last.key, cursor: last.next, edge: 'next', error: ''});
            for (let index = children.length - 1; index >= 0; index--) tasks.push({row: children[index]!});
        }
        if (this.restoring && !this.restorationRows().length) this.restoring = false;
        this.snapshot = { restoring: this.restoring, root: this.root, canWrite: this.branches.get(this.root?.id ?? null)?.canWrite ?? false, rows, expanded: new Set(this.expanded), loading: new Set(this.requests.keys()), version: this.snapshot.version + 1 };
        for (const listener of this.listeners)
            listener();
    }
    restore(ids: readonly (string | null)[]) {
        this.restoring = true;
        this.expanded = new Set(ids.filter(id => id === null || typeof id === 'string' && id.length <= 128).slice(0, MAX_EXPANDED));
        if (this.expanded.size)
            this.expanded.add(null);
        for(const parent of this.expanded)this.branch(parent);
        this.trim();
        this.publish();
    }
    reveal(path: readonly FolderSummary[], collapsed = path.length > 8) {
        const root = collapsed ? path.at(-1) ?? null : null;
        const changed = this.root?.id !== root?.id;
        this.root = root;
        this.path = path.slice(-8).map(folder => ({ ...folder }));
        if (changed) {
            for (const request of this.requests.values()) request.controller.abort();
            this.visible.clear();
        }
        if (root) this.expanded.add(root.id);
        if (!path.length) {
            this.publish();
            return;
        }
        this.expanded.add(null);
        this.path.forEach((folder, index) => {
            const branch = this.branch(folder.id);
            branch.entry = { ...folder };
            branch.parent = index ? path[index - 1]!.id : null;
            if (!root && index < this.path.length - 1)
                this.expanded.add(folder.id);
        });
        this.trim();
        this.publish();
    }
    pathTo(folder: FolderSummary): FolderSummary[] {
        const path = [folder];
        let parent = this.snapshot.rows.find(row => row.folder?.id === folder.id)?.parent ?? null;
        const seen = new Set([folder.id]);
        while (parent !== null && path.length < 8 && !seen.has(parent)) {
            seen.add(parent);
            const branch = this.branches.get(parent);
            if (!branch?.entry)
                break;
            path.unshift(branch.entry);
            parent = branch.parent;
        }
        return path;
    }
    parentOf(folder: FolderSummary): FolderSummary | null {
        const parent = this.branches.get(folder.id)?.parent;
        return parent ? this.branches.get(parent)?.entry ?? null : null;
    }
    toggle(id: string | null, folder?: FolderSummary, parent: string | null = null) {
        if (this.expanded.has(id)) {
            this.expanded.delete(id);
            for (const request of this.requests.values())
                if (!this.isVisible(request.parent))
                    request.controller.abort();
        }
        else {
            const branch = this.branch(id);
            if (folder) {
                branch.entry = folder;
                branch.parent = parent;
            }
            this.expanded.add(id);
            if (id !== null && this.expanded.size > MAX_EXPANDED && folder) {
                this.path = this.pathTo(folder);
                this.root = folder;
                this.expanded = new Set([null, id]);
            }
            this.trim();
        }
        this.publish();
    }
    private isVisible(parent: string | null): boolean {
        const seen = new Set<string | null>();
        while (!seen.has(parent)) {
            seen.add(parent);
            if (!this.expanded.has(parent))
                return false;
            if (parent === (this.root?.id ?? null))
                return true;
            parent = this.branches.get(parent)?.parent ?? null;
        }
        return false;
    }
    retry(row: FolderTreeRow) {
        const branch = this.branches.get(row.parent);
        const page = branch?.pages.find(page => page.key === row.page);
        if (page) {
            page.error = '';
            if (page.cursor !== null && branch)
                branch.pages = [this.page(null)];
        }
        this.publish();
    }
    areRowsReady(rows: readonly FolderTreeRow[]): boolean {
        return rows.every(row => {
            if (row.edge || row.error) return true;
            const page = this.branches.get(row.parent)?.pages.find(page => page.key === row.page);
            return !!page?.loaded;
        });
    }
    private restorationRows(): FolderTreeRow[] {
        const pending: FolderTreeRow[] = [];
        for(const parent of this.expanded){
            // Creating a descriptor does not load data. In a hoisted context only
            // branches reachable from its local root are admitted.
            const branch=this.branch(parent);
            if(!this.isVisible(parent))continue;
            for(const page of branch.pages){
                if(page.loaded||page.error)continue;
                pending.push({key:`restore:${parent}:${page.key}`,depth:0,parent,folder:null,page:page.key,cursor:page.cursor,edge:null,error:''});
            }
        }
        return pending;
    }
    loadVisible(rows: readonly FolderTreeRow[]) {
        this.suspended = false;
        this.visible = new Set(rows.map(row => this.requestKey(row.parent, row.page)));
        this.visibleFolders = new Set(rows.flatMap(row => row.folder ? [row.folder.id] : []));
        const admitted = this.restoring ? [...rows,...this.restorationRows()] : rows;
        for (const row of admitted) {
            const page = this.branches.get(row.parent)?.pages.find(page => page.key === row.page);
            if (!page || row.error || page.error || !row.edge && !page.stale && (page.items !== null || row.folder !== null && page.loaded))
                continue;
            if (this.requests.size >= this.concurrency)
                break;
            void this.load(row);
        }
    }
    private async load(row: FolderTreeRow) {
        const key = this.requestKey(row.parent, row.page);
        if (this.requests.has(key) || !this.isVisible(row.parent))
            return;
        const branch = this.branch(row.parent);
        const page = branch.pages.find(page => page.key === row.page);
        if (!page)
            return;
        const controller = new AbortController();
        this.requests.set(key, { controller, parent: row.parent });
        this.publish();
        try {
            const result = await this.browse(this.workspace, { view: 'folders', parent: row.parent, search: '', sort: 'name', direction: 'asc', limit: this.pageSize }, row.cursor, controller.signal);
            if (controller.signal.aborted || this.suspended || this.branches.get(row.parent) !== branch)
                return;
            this.validate(result, row.cursor);
            branch.canWrite = result.canWrite;
            const loaded = { ...this.page(row.cursor), items: result.items.map(({ id, name, hasChildFolders, readOnly }) => ({ id, name, ...(hasChildFolders !== undefined ? { hasChildFolders } : {}), ...(readOnly !== undefined ? { readOnly } : {}) })), count: result.items.length, next: result.nextCursor, previous: result.previousCursor, loaded: true, used: ++this.clock };
            if (row.edge) {
                if (row.edge === 'next') {
                    branch.pages.push(loaded);
                    if (branch.pages.length > MAX_PAGES)
                        branch.pages.shift();
                }
                else {
                    branch.pages.unshift(loaded);
                    if (branch.pages.length > MAX_PAGES)
                        branch.pages.pop();
                }
            }
            else {
                loaded.key = page.key;
                branch.pages[branch.pages.indexOf(page)] = loaded;
            }
            if (row.parent !== null && branch.entry && loaded.items.length === 0 && !loaded.next && !loaded.previous) {
                branch.entry.hasChildFolders = false;
                for (const value of this.branches.values())
                    for (const cached of value.pages)
                        for (const folder of cached.items ?? [])
                            if (folder.id === row.parent)
                                folder.hasChildFolders = false;
                this.expanded.delete(row.parent);
            }
            this.trim();
        }
        catch (error) {
            if (!controller.signal.aborted) {
                page.error = error instanceof Error ? error.message : String(error);
                if (row.edge) {
                    const failed = this.page(row.cursor);
                    failed.error = page.error;
                    if (row.edge === 'next') {
                        page.next = null;
                        branch.pages.push(failed);
                    }
                    else {
                        page.previous = null;
                        branch.pages.unshift(failed);
                    }
                    if (branch.pages.length > MAX_PAGES)
                        branch.pages.splice(row.edge === 'next' ? 0 : MAX_PAGES, 1);
                }
            }
        }
        finally {
            if (this.requests.get(key)?.controller === controller)
                this.requests.delete(key);
            this.publish();
        }
    }
    private validate(result: EntryPage, cursor: string | null) {
        if (result.items.length > this.pageSize || result.items.some(folder => folder.kind !== 'folder') || cursor !== null && (result.nextCursor === cursor || result.previousCursor === cursor))
            throw new Error('Invalid folder cursor page.');
    }
    invalidate(parents: readonly (string | null)[]) {
        for (const parent of new Set(parents)) {
            const branch = this.branches.get(parent);
            if (!branch)
                continue;
            for (const request of this.requests.values())
                if (request.parent === parent)
                    request.controller.abort();
            const previous = branch.pages[0];
            branch.pages = [previous && (previous.items || previous.retained?.size) ? {...previous, cursor: null, next: null, previous: null, stale: true, error: ''} : this.page(null)];
            if (branch.entry)
                branch.entry.hasChildFolders = true;
            for (const value of this.branches.values())
                for (const page of value.pages)
                    for (const folder of page.items ?? [])
                        if (folder.id === parent)
                            folder.hasChildFolders = true;
        }
        this.publish();
    }
    changed(entry: Entry, parent?: string | null) {
        if (entry.kind !== 'folder')
            return;
        const parents = new Set<string | null>([parent ?? null]);
        for (const [id, branch] of this.branches)
            if (branch.pages.some(page => page.items?.some(folder => folder.id === entry.id) || [...(page.retained?.values()??[])].some(folder=>folder.id===entry.id)))
                parents.add(id);
        const known = this.branches.get(entry.id);
        if (known)
            parents.add(known.parent);
        if (entry.trashedAt) {
            this.expanded.delete(entry.id);
            this.path = this.path.filter(folder => folder.id !== entry.id);
        }
        this.invalidate([...parents]);
    }
    applyChanges(changes: readonly {payload: Record<string, unknown>}[], reset = false) {
        if (reset) {this.refresh(); return;}
        const parents = new Set<string | null>();
        for (const change of changes) {
            const nodes = change.payload.nodes;
            if (!Array.isArray(nodes)) {this.refresh(); return;}
            for (const node of nodes) {
                if (!node || typeof node !== 'object' || node.kind !== 'folder' || typeof node.id !== 'string' || typeof node.name !== 'string') continue;
                for (const parent of [node.parentId, node.previousParentId]) if (parent === null || typeof parent === 'string') parents.add(parent);
                const update = (folder: FolderSummary) => folder.id === node.id ? {...folder, name: node.name} : folder;
                this.path = this.path.map(update);
                if (this.root) this.root = update(this.root);
                for (const branch of this.branches.values()) {
                    if (branch.entry) branch.entry = update(branch.entry);
                    for (const page of branch.pages) {
                        if (page.items) page.items = page.items.map(update);
                        if (page.retained) page.retained = new Map([...page.retained].map(([offset, folder]) => [offset, update(folder)]));
                    }
                }
                if (node.trashedAt) this.expanded.delete(node.id);
            }
        }
        if (parents.size) this.invalidate([...parents]);
    }
    refresh() { this.invalidate([...this.expanded]); }
    private trim() {
        const pinned = new Set(this.path.map(folder => folder.id));
        while (this.expanded.size > MAX_EXPANDED) {
            const oldest = [...this.expanded].find(id => id !== null && !pinned.has(id));
            if (oldest === undefined)
                break;
            this.expanded.delete(oldest);
        }
        // The expanded outline is navigation state, not an expendable response
        // cache. Its names survive until explicit collapse or cursor-window eviction.
        // Otherwise scrolling back would rebuild entire expanded subtrees.
        const idle = [...this.branches].flatMap(([parent,branch])=>
            this.expanded.has(parent)||pinned.has(parent??'') ? [] : branch.pages
                .filter(page=>page.items!==null&&!this.visible.has(this.requestKey(parent,page.key)))
                .map(page=>({parent,page}))).sort((a,b)=>a.page.used-b.page.used);
        while(idle.length>this.cachedPages){
            const {page}=idle.shift()!;
            page.retained=new Map((page.items??[]).flatMap((folder,index)=>
                this.expanded.has(folder.id)||pinned.has(folder.id)?[[index,folder] as const]:[]));
            page.items=null;
        }
        while (this.branches.size > MAX_BRANCHES) {
            const victim = [...this.branches].filter(([id]) => id !== null && !this.expanded.has(id) && !pinned.has(id)).sort((a, b) => a[1].used - b[1].used)[0];
            if (!victim)
                break;
            this.branches.delete(victim[0]);
        }
    }
    suspend() { this.suspended = true; for (const request of this.requests.values())
        request.controller.abort(); this.requests.clear(); }
    getStats() {
        const pages = [...this.branches.values()].flatMap(branch => branch.pages);
        return { branches: this.branches.size, pages: pages.length, cachedPages: pages.filter(page => page.items !== null).length, folders: pages.reduce((count, page) => count + (page.items?.length ?? 0), 0), idleCachedPages: [...this.branches].reduce((count,[parent,branch])=>count+(this.expanded.has(parent)?0:branch.pages.filter(page=>page.items!==null).length),0), summaries: pages.reduce((count,page)=>count+(page.items?.length??page.retained?.size??0),0), expanded: this.expanded.size, requests: this.requests.size };
    }
    destroy() { this.suspend(); this.expanded.clear(); this.path = []; this.listeners.clear(); this.branches.clear(); }
}
/** Observe successful writes at the transport boundary, including custom transports. */
export function createFolderTreeTransport(source: StudioTransport, options: FolderTreeOptions = {}) {
    const clients = new Map<string, FolderTreeClient>();
    const tree = (workspace: string) => {
        let client = clients.get(workspace);
        if (client) {
            clients.delete(workspace);
            clients.set(workspace, client);
            return client;
        }
        client = new FolderTreeClient(workspace, source.browse.bind(source), options);
        clients.set(workspace, client);
        if (clients.size > 4) {
            const oldest = clients.keys().next().value!;
            clients.get(oldest)!.destroy();
            clients.delete(oldest);
        }
        return client;
    };
    const overrides: Partial<StudioTransport> = {
        async createFolder(workspace, parent, name, signal) { const entry = await source.createFolder(workspace, parent, name, signal); clients.get(workspace)?.changed(entry, parent); return entry; },
        async update(workspace, id, input, signal) { const entry = await source.update(workspace, id, input, signal); clients.get(workspace)?.changed(entry, input.parent); return entry; },
        async trash(workspace, id, revision, signal) { const entry = await source.trash(workspace, id, revision, signal); clients.get(workspace)?.changed(entry); return entry; },
        async restore(workspace, id, revision, signal) { const entry = await source.restore(workspace, id, revision, signal); clients.get(workspace)?.changed(entry); return entry; },
        ...(source.bulk ? { async bulk(workspace, input, signal) { const result = await source.bulk!(workspace, input, signal); const client = clients.get(workspace); for (const entry of result.items)
                client?.changed(entry, input.operation === 'move' ? input.parent : undefined); return result; } } satisfies Partial<StudioTransport> : {}),
    };
    const methods = new Map<PropertyKey, {
        source: Function;
        bound: Function;
    }>();
    const transport = new Proxy(source, {
        get(target, key) {
            if (Object.hasOwn(overrides, key))
                return Reflect.get(overrides, key);
            const value = Reflect.get(target, key, target);
            if (typeof value !== 'function')
                return value;
            const cached = methods.get(key);
            if (cached && cached.source === value)
                return cached.bound;
            const bound = value.bind(target);
            methods.set(key, { source: value, bound });
            return bound;
        },
    });
    return { tree, transport, destroy() { for (const client of clients.values())
            client.destroy(); clients.clear(); methods.clear(); } };
}
