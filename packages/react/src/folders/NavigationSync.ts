import type {FolderNavigationTransport, NavigationChangePage} from './contracts';

/** Ordered, bounded replay. Push is a hint; every delta is authenticated by the transport. */
export class NavigationSync {
  private revision = 0;
  private controller = new AbortController();
  private pending: Promise<void> | null = null;
  private dirty = false;
  private unsubscribe: (() => void) | undefined;
  private stopped = false;
  constructor(readonly workspace: string, private transport: FolderNavigationTransport, private changed: (page: NavigationChangePage) => void) {}
  start() {
    if (this.stopped) return;
    this.unsubscribe ??= this.transport.subscribe?.(this.workspace, () => {void this.refresh();});
    void this.refresh();
  }
  refresh(): Promise<void> {
    if (this.pending) {this.dirty = true; return this.pending;}
    if (!this.transport.changes || this.stopped) return Promise.resolve();
    const pending = Promise.resolve().then(async () => {
      const committedRevision = this.revision;
      let cursor = committedRevision;
      let count = 0;
      let changed = false;
      const items: NavigationChangePage['items'] = [];
      let result: NavigationChangePage | null = null;
      do {
        this.dirty = false;
        const page = await this.transport.changes!(this.workspace, cursor, this.controller.signal);
        if (this.stopped) return;
        if (!Number.isSafeInteger(page.nextRevision) || page.nextRevision < 0 || page.items.length > 100 || page.nextRevision < cursor && !page.reset) throw new Error('Invalid navigation revision.');
        let expected = cursor + 1;
        for (const item of page.items) {
          if (item.revision !== expected++) throw new Error('Navigation replay has a gap.');
        }
        changed ||= page.reset || page.items.length > 0;
        items.push(...page.items);
        if (page.items.length && page.nextRevision !== expected - 1) throw new Error('Invalid replay continuation.');
        if (page.hasMore && page.nextRevision <= cursor && !page.reset) throw new Error('Navigation replay did not advance.');
        cursor = page.nextRevision;
        result = page;
        if (!page.hasMore && !this.dirty) break;
      } while (++count < 5);
      if (result && (changed || result.hasMore)) {
        // A large backlog triggers a fresh view, rather than retaining unbounded history.
        const reset = result.reset || result.hasMore;
        const revision = reset ? result.revision : cursor;
        this.changed({...result, items: reset ? [] : items, nextRevision: revision, reset, hasMore: false});
        this.revision = revision;
      } else if (result) this.revision = cursor;
    }).catch(() => {
      // Keep the displayed data; focus or the next signal retries a transient failure.
    }).finally(() => {this.pending = null;});
    this.pending = pending;
    return pending;
  }
  destroy() {this.stopped = true; this.controller.abort(); this.unsubscribe?.(); this.unsubscribe = undefined;}
}
