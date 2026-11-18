import type {AncestorPage, FolderContext, FolderNavigationTransport} from './contracts';

export interface NavigationSnapshot {
  context: FolderContext | null;
  pages: readonly AncestorPage[];
  loading: boolean;
  error: Error | null;
  /** Earlier loaded ancestors were evicted. resetAncestors restores the nearest page. */
  windowed: boolean;
}

/** Framework-independent ancestor navigation; one admitted read and a bounded page window. */
export class FolderNavigationClient {
  private controller: AbortController | null = null;
  private listeners = new Set<() => void>();
  private generation = 0;
  private state: NavigationSnapshot = {context: null, pages: [], loading: false, error: null, windowed: false};
  private pending: Promise<unknown> | null = null;
  private destroyed = false;

  constructor(readonly workspace: string, private transport: FolderNavigationTransport) {}
  getSnapshot = () => this.state;
  subscribe = (listener: () => void) => {this.listeners.add(listener); return () => {this.listeners.delete(listener);};};
  private publish(patch: Partial<NavigationSnapshot>) {
    if (this.destroyed) return;
    this.state = {...this.state, ...patch};
    for (const listener of this.listeners) listener();
  }
  async open(folder: string): Promise<FolderContext> {
    if (this.destroyed) throw new Error('Folder navigation is disposed.');
    this.cancel();
    const generation = this.generation;
    const controller = this.controller = new AbortController();
    this.publish({context: null, pages: [], loading: true, error: null, windowed: false});
    try {
      const context = await this.transport.context(this.workspace, folder, controller.signal);
      if (controller.signal.aborted || generation !== this.generation) throw new DOMException('Aborted', 'AbortError');
      if (context.folderId !== folder || context.tail.length > 4 || context.tail.at(-1)?.id !== folder || new Set(context.tail.map(item => item.id)).size !== context.tail.length) throw new Error('Invalid folder context.');
      this.publish({context, loading: false});
      return context;
    } catch (error) {
      if (!controller.signal.aborted && generation === this.generation) this.publish({loading: false, error: error instanceof Error ? error : new Error(String(error))});
      throw error;
    } finally {
      if (this.controller === controller) this.controller = null;
    }
  }
  loadMore(): Promise<void> {
    if (this.pending) return this.pending as Promise<void>;
    const context = this.state.context;
    const cursor = this.state.pages.at(-1)?.nextCursor ?? (this.state.pages.length ? null : context?.ancestorCursor);
    if (!context || !cursor || this.destroyed) return Promise.resolve();
    const controller = this.controller = new AbortController();
    const generation = this.generation;
    this.publish({loading: true, error: null});
    const pending = (async () => {
      try {
        const page = await Promise.resolve().then(() => this.transport.ancestors(this.workspace, context.folderId, cursor, 50, controller.signal));
        if (controller.signal.aborted || generation !== this.generation) return;
        if (page.items.length > 50 || page.nextCursor === cursor || page.revision !== context.revision || new Set(page.items.map(item => item.id)).size !== page.items.length) throw new Error('Invalid ancestor page.');
        const pages = [...this.state.pages, page];
        this.publish({pages: pages.slice(-4), windowed: this.state.windowed || pages.length > 4, loading: false});
      } catch (error) {
        if (!controller.signal.aborted && generation === this.generation) this.publish({loading: false, error: error instanceof Error ? error : new Error(String(error))});
      } finally {
        if (this.controller === controller) this.controller = null;
        if (generation === this.generation) this.pending = null;
      }
    })();
    this.pending = pending;
    return pending;
  }
  resetAncestors() {this.cancel(); this.publish({pages: [], loading: false, error: null, windowed: false});}
  cancel() {this.generation++; this.controller?.abort(); this.controller = null; this.pending = null; this.publish({loading: false});}
  destroy() {this.cancel(); this.listeners.clear(); this.destroyed = true;}
}
