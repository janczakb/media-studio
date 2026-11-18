import {useCallback, useEffect, useRef, useState} from 'react';
import type {LibrarySearchQuery, LibrarySearchResult, LibrarySearchTransport} from './contracts';

const MAX_RESULTS = 2048;
type State = {key: string; items: LibrarySearchResult[]; cursor: string | null; loading: boolean; settled: boolean; failed: boolean; limited: boolean};
const empty = (key: string, loading = false): State => ({key, items: [], cursor: null, loading, settled: false, failed: false, limited: false});

/** Manages one authorized search scope and ignores responses from superseded queries. */
export function useLibrarySearch(
  transport: LibrarySearchTransport,
  workspace: string,
  query: LibrarySearchQuery,
  enabled: boolean,
  debounce = 180,
) {
  const serialized = JSON.stringify(query);
  const key = JSON.stringify([workspace, serialized]);
  const [state, setState] = useState<State>(() => empty(key));
  const [retry, setRetry] = useState(0);
  const request = useRef<AbortController | null>(null);
  const cursors = useRef(new Set<string>());
  const run = useCallback((cursor: string | null) => {
    if (request.current || (cursor !== null && cursors.current.has(cursor))) return;
    const controller = new AbortController();
    request.current = controller;
    setState(current => current.key === key ? {...current, loading: true, failed: false} : empty(key, true));
    void transport(workspace, JSON.parse(serialized) as LibrarySearchQuery, cursor, controller.signal).then(page => {
      if (request.current !== controller) return;
      if (cursor !== null) cursors.current.add(cursor);
      setState(current => {
        if (current.key !== key) return current;
        const items = new Map((cursor ? current.items : []).map(entry => [entry.id, entry]));
        for (const entry of page.items) items.set(entry.id, entry);
        const limited = items.size >= MAX_RESULTS && page.hasMore;
        const next = page.hasMore && page.nextCursor && !cursors.current.has(page.nextCursor) ? page.nextCursor : null;
        return {key, items: [...items.values()].slice(0, MAX_RESULTS), cursor: limited ? null : next, loading: false, settled: true, failed: false, limited};
      });
    }).catch(() => {
      if (request.current === controller) setState(current => current.key === key ? {...current, loading: false, failed: true} : current);
    }).finally(() => {
      if (request.current === controller) request.current = null;
    });
  }, [key, serialized, transport, workspace]);

  useEffect(() => {
    if (!enabled) return;
    setState(empty(key, true));
    cursors.current.clear();
    const timer = setTimeout(() => run(null), query.text ? debounce : 0);
    return () => {
      clearTimeout(timer);
      request.current?.abort();
      request.current = null;
    };
  }, [key, run, retry, enabled, debounce, query.text, query.mode]);

  const visible = state.key === key ? state : empty(key, enabled);
  return {
    ...visible,
    loadMore: () => {if (enabled && visible.cursor) run(visible.cursor);},
    retry: () => {if (visible.cursor) run(visible.cursor);else setRetry(count => count + 1);},
  };
}
