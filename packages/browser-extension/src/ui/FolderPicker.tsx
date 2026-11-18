import {Button, Input, Spinner} from '@heroui/react';
import {useEffect, useLayoutEffect, useMemo, useRef, useState} from 'react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {ArrowLeft01Icon, ArrowRight01Icon, Folder01Icon, Tick02Icon} from '@hugeicons/core-free-icons';
import {send} from '../core/webextension';
import {Field, Icon, words} from './shared';

import {mergeFolderPage, folderRowHeight, type CaptureFolder as Folder, type FolderPage as Page, type FolderDirection} from '../core/folder-window';
interface Props {
  connectionId: string;
  locale: string;
  value: Folder | null;
  onChange: (folder: Folder | null) => void;
  inPage?: boolean;
  canCreate?: boolean;
  compact?: boolean;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  onDropFolder?: (folder: Folder | null) => void;
}

/** Bidirectional cursor loading, a bounded data window and fixed-height virtual rows. */
export function FolderPicker({connectionId, locale, value, onChange, inPage = false, canCreate = true, compact = false, onDropFolder, searchValue, onSearchChange}: Props) {
  const t = words(locale);
  const [path, setPath] = useState<Folder[]>([]);
  const [localSearch, setLocalSearch] = useState('');
  const search = searchValue ?? localSearch;
  const setSearch = onSearchChange ?? setLocalSearch;
  const [newName, setNewName] = useState('');
  const [window, setWindow] = useState<{pages: Page[]; scrollDelta: number; reset: boolean}>({pages: [], scrollDelta: 0, reset: true});
  const [loadingMore, setLoadingMore] = useState<FolderDirection | null>(null);
  const loadingMoreRef = useRef(false);
  const failedDirection = useRef<FolderDirection | null>(null);
  const items = useMemo(() => window.pages.flatMap(page => page.items), [window.pages]);
  const [retry, setRetry] = useState(0);
  const [pending, setPending] = useState(true);
  const [error, setError] = useState('');
  const viewport = useRef<HTMLDivElement>(null);
  const generation = useRef(0);
  const parent = path.at(-1) ?? null;
  const listAction = inPage ? 'drag.folders' : 'folders';
  const createAction = inPage ? 'drag.folderCreate' : 'folder.create';
  const virtual = useVirtualizer({count: items.length, getScrollElement: () => viewport.current, estimateSize: () => folderRowHeight, overscan: 3, getItemKey: index => items[index]?.id ?? index});

  useEffect(() => {
    const request = ++generation.current;
    setPending(true);
    setError('');
    loadingMoreRef.current = false;
    setLoadingMore(null);
    failedDirection.current = null;
    const timer = setTimeout(() => {
      void send<Page>(listAction, {connectionId, parent: parent?.id, search}).then(result => {
        if (generation.current === request) setWindow({pages: [{...result, items: result.items.slice(0, 60)}], scrollDelta: 0, reset: true});
      }, failure => {if (generation.current === request) setError(String(failure));})
        .finally(() => {if (generation.current === request) setPending(false);});
    }, search ? 200 : 0);
    return () => {++generation.current;clearTimeout(timer);};
  }, [connectionId, parent?.id, search, listAction, retry]);

  useLayoutEffect(() => {
    if (window.reset) virtual.scrollToOffset(0);
    else if (window.scrollDelta) virtual.scrollToOffset(Math.max(0, (viewport.current?.scrollTop ?? 0) + window.scrollDelta));
  }, [window]);

  async function loadMore(direction: FolderDirection) {
    if (pending || loadingMoreRef.current) return;
    const edge = direction === 'next' ? window.pages.at(-1) : window.pages[0];
    const cursor = direction === 'next' ? edge?.nextCursor : edge?.previousCursor;
    if (!cursor) return;
    const request = generation.current;
    loadingMoreRef.current = true;
    setLoadingMore(direction);
    setError('');
    failedDirection.current = direction;
    try {
      const result = await send<Page>(listAction, {connectionId, parent: parent?.id, search, ...(direction === 'next' ? {after: cursor} : {before: cursor})});
      if (request === generation.current) {
        setWindow(current => ({...mergeFolderPage(current.pages, result, direction), reset: false}));
        failedDirection.current = null;
      }
    } catch (failure) {if (request === generation.current) setError(String(failure));}
    finally {if (request === generation.current) {loadingMoreRef.current = false;setLoadingMore(null);}}
  }

  const visibleRows = virtual.getVirtualItems();
  const firstVisible = visibleRows[0]?.index ?? 0;
  const lastVisible = visibleRows.at(-1)?.index ?? -1;
  useEffect(() => {
    if (pending || loadingMore || error || !items.length) return;
    if (firstVisible <= 3 && (viewport.current?.scrollTop ?? 0) < 72 && virtual.scrollDirection === 'backward' && window.pages[0]?.previousCursor) void loadMore('previous');
    else if (lastVisible >= items.length - 7 && window.pages.at(-1)?.nextCursor) void loadMore('next');
  }, [firstVisible, lastVisible, items.length, pending, loadingMore, error, window.pages]);

  function enter(folder?: Folder) {
    ++generation.current;
    setPending(true);
    setPath(current => folder ? [...current, folder] : current.slice(0, -1));
    setSearch('');
  }

  function filter(value: string) {
    ++generation.current;
    setPending(true);
    setSearch(value);
  }

  async function create() {
    const name = newName.trim();
    if (!name || pending) return;
    const request = ++generation.current;
    setPending(true);
    setError('');
    try {
      const folder = await send<Folder>(createAction, {connectionId, name, parent: parent?.id});
      if (request === generation.current) {onChange(folder);setNewName('');setRetry(value => value + 1);}
    } catch (failure) {if (request === generation.current) setError(String(failure));}
    finally {if (request === generation.current) setPending(false);}
  }

  const drop = (folder: Folder | null) => ({
    onDragOver: (event: React.DragEvent) => {if (pending || !onDropFolder) return;event.preventDefault();event.currentTarget.classList.add('is-over');},
    onDragLeave: (event: React.DragEvent) => {if (!event.currentTarget.contains(event.relatedTarget as Node)) event.currentTarget.classList.remove('is-over');},
    onDrop: (event: React.DragEvent) => {event.preventDefault();event.stopPropagation();event.currentTarget.classList.remove('is-over');if (!pending) onDropFolder?.(folder);},
  });

  // Native clicks remain inside the modal shadow tree. React Aria's press
  // completion can miss pointer releases retargeted to the shadow host.
  const activate = (action: () => void) => inPage ? {onClickCapture: action} : {onPress: action};

  const currentSelected = (parent?.id ?? null) === (value?.id ?? null);

  return <div className={'fc-folder-picker' + (compact ? ' is-compact' : '')}>
    {!compact && <strong>{t.folder}{value ? ' · ' + value.name : ''}</strong>}
    <div className="fc-folder-crumbs">
      {parent && <Button isIconOnly size="sm" variant="ghost" aria-label={t.back} isDisabled={pending} {...activate(() => enter())}><Icon icon={ArrowLeft01Icon} size={16}/></Button>}
      <Button className="fc-current-folder fc-folder-destination" size="sm" variant="ghost" aria-pressed={currentSelected} {...activate(() => onChange(parent))} {...drop(parent)}>
        <Icon icon={Folder01Icon} size={16}/>
        <span>{parent?.name ?? t.root}</span>
        {currentSelected && <span className="fc-folder-check" aria-hidden="true"><Icon icon={Tick02Icon} size={15}/></span>}
      </Button>
    </div>
    {searchValue === undefined && <div className="fc-folder-search">{compact ? <Input aria-label={t.searchFolders} placeholder={t.searchFolders} variant="secondary" value={search} onChange={event => filter(event.target.value)}/> : <Field label={t.searchFolders} placeholder={t.searchFolders} value={search} onChange={filter}/>}</div>}
    <div className="fc-folder-results" ref={viewport} aria-busy={pending || !!loadingMore} role="list" aria-label={t.folder}>
      <div className="fc-folder-virtual" style={{height: virtual.getTotalSize()}}>
        {virtual.getVirtualItems().map(row => {
          const folder = items[row.index];
          if (!folder) return null;
          const selected = value?.id === folder.id;
          return <div key={folder.id} role="listitem" className="fc-folder-row" style={{height: row.size, transform: `translateY(${row.start}px)`}} {...drop(folder)}>
            <Button className="fc-folder-destination" size="sm" variant="ghost" aria-pressed={selected} isDisabled={pending} {...activate(() => onChange(folder))}>
              <Icon icon={Folder01Icon} size={17}/>
              <span>{folder.name}</span>
              {selected && <span className="fc-folder-check" aria-hidden="true"><Icon icon={Tick02Icon} size={15}/></span>}
            </Button>
            <Button isIconOnly size="sm" variant="ghost" isDisabled={pending} aria-label={t.openFolder.replace('{name}', folder.name)} {...activate(() => enter(folder))}><Icon icon={ArrowRight01Icon} size={15}/></Button>
          </div>;
        })}
      </div>
      {pending && <div className="fc-folder-loading"><Spinner size="sm" aria-label={t.loading}/></div>}
      {loadingMore && <div className={"fc-folder-more is-" + loadingMore}><Spinner size="sm" aria-label={t.loading}/></div>}
      {!pending && !error && !items.length && <p className="fc-folder-empty">{search.trim() ? t.noMatchingFolders : t.noFolders}</p>}
    </div>
    {canCreate && <div className="fc-folder-create"><Field label={t.newFolder} value={newName} onChange={setNewName}/><Button size="sm" variant="secondary" isDisabled={!newName.trim() || pending} {...activate(() => void create())}>{t.newFolder}</Button></div>}
    {error && <div className="fc-folder-error" role="alert"><span>{error}</span><Button size="sm" variant="ghost" {...activate(() => failedDirection.current ? void loadMore(failedDirection.current) : setRetry(value => value + 1))}>{t.retry}</Button></div>}
  </div>;
}
