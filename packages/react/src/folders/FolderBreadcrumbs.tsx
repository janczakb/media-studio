import folderArtwork from '../../assets/folder.svg';
import {ArrowLeft02Icon} from '@hugeicons/core-free-icons';
import {Icon} from '../ui';
import {captureScrollAnchor,restoreScrollAnchor,type ScrollAnchor} from './scroll-anchor';
import {Breadcrumbs, Button, Popover, ScrollShadow, SearchField, Spinner} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useCallback, useEffect, useLayoutEffect, useRef, useState, useSyncExternalStore} from 'react';
import type {FolderNavigationClient} from './FolderNavigationClient';
import type {NavigationFolder} from './contracts';
import type {Messages} from '../ui';

export function FolderBreadcrumbs({path, collapsed, client, portal, t, onRoot, onFolder, onBack, rootLabel}: {
  path: readonly NavigationFolder[]; collapsed: boolean; client: FolderNavigationClient | null;
  onBack?:()=>void; rootLabel?:string;
  portal: HTMLElement; t: Messages; onRoot: () => void; onFolder: (folder: NavigationFolder) => void;
}) {
  const container = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(3);
  const [open, setOpen] = useState(false);
  useLayoutEffect(() => {
    const node = container.current;
    if (!node) return;
    const observer = new ResizeObserver(([entry]) => {
      const width = entry?.contentRect.width ?? 0;
      setVisible(width < 320 ? 1 : width < 560 ? 2 : 3);
    });
    observer.observe(node);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {setOpen(false);}, [path.at(-1)?.id]);
  const shown = path.slice(-visible);
  const hidden = path.slice(0, Math.max(0, path.length - shown.length)).reverse();
  const hasMenu = hidden.length > 0 || collapsed;
  return <div ref={container} className="fms-folder-breadcrumbs">{onBack&&<Button isIconOnly variant="ghost" className="fms-breadcrumb-back" aria-label={t.back} onPress={onBack}><Icon icon={ArrowLeft02Icon} size={20}/></Button>}<Breadcrumbs separator={<span className="fms-breadcrumb-slash" aria-hidden="true">/</span>} className="fms-breadcrumbs" aria-label={t.files} onAction={key=>{if(key==='root')onRoot();else{const folder=path.find(item=>'folder:'+item.id===key);if(folder)onFolder(folder);}}}>
    <Breadcrumbs.Item id="root"><span className="fms-breadcrumb-label">{rootLabel??t.files}</span></Breadcrumbs.Item>
    {hasMenu && <Breadcrumbs.Item><Popover isOpen={open} onOpenChange={setOpen}><Button variant="ghost" size="sm" className="fms-breadcrumb-gap" aria-label={t.folder}>…</Button><Popover.Content placement="bottom start" UNSTABLE_portalContainer={portal} className="fms-ancestor-popover"><Popover.Dialog aria-label={t.folder}><div onKeyDownCapture={event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();setOpen(false);}}}>{open && <AncestorMenu client={client} hidden={hidden} t={t} onFolder={folder => {setOpen(false); onFolder(folder);}}/>}</div></Popover.Dialog></Popover.Content></Popover></Breadcrumbs.Item>}
    {shown.map((folder,index) => <Breadcrumbs.Item key={folder.id} id={'folder:'+folder.id}><span className="fms-breadcrumb-folder">{index===shown.length-1&&<img src={folderArtwork} alt="" aria-hidden="true"/>}<span className="fms-breadcrumb-label" title={folder.name}>{folder.name}</span></span></Breadcrumbs.Item>)}
  </Breadcrumbs></div>;
}
const empty = {context: null, pages: [], loading: false, error: null, windowed: false} as const;
function AncestorMenu({client, hidden, t, onFolder}: {client: FolderNavigationClient | null; hidden: readonly NavigationFolder[]; t: Messages; onFolder: (folder: NavigationFolder) => void}) {
  const scroll = useRef<HTMLDivElement>(null);
  const anchor = useRef<ScrollAnchor|null>(null);
  const subscribe = useCallback((notify:()=>void)=>client?.subscribe(()=>{anchor.current??=captureScrollAnchor(scroll.current,'[data-ancestor-key]','data-ancestor-key');notify();})??(()=>{}),[client]);
  const state = useSyncExternalStore(subscribe, client?.getSnapshot ?? (() => empty), () => empty);
  const height = useRef(state.context?.ancestorCursor ? 288 : Math.min(288,Math.max(72,hidden.length*36))).current;
  const [search, setSearch] = useState('');
  const remote = state.pages.flatMap(page => page.items);
  const items = [...(state.windowed ? [] : hidden), ...remote].filter(folder => folder.name.toLocaleLowerCase().includes(search.toLocaleLowerCase()));
  const hasMore = state.pages.length ? !!state.pages.at(-1)?.nextCursor : !!state.context?.ancestorCursor;
  const virtual = useVirtualizer({count: items.length + (!search && (hasMore || state.error) ? 1 : 0), getScrollElement: () => scroll.current, estimateSize: () => 36, overscan: 3, getItemKey: index => items[index]?.id ?? 'next'});
  const rows = virtual.getVirtualItems();
  useLayoutEffect(()=>{restoreScrollAnchor(scroll.current,items.map(folder=>folder.id),36,anchor.current);anchor.current=null;},[state.pages,search]);
  useEffect(() => {
    const viewport=scroll.current;
    const end=viewport?Math.ceil((viewport.scrollTop+viewport.clientHeight)/36)+3:0;
    if (!search && hasMore && end >= items.length && !state.loading && !state.error) void client?.loadMore();
  }, [client, search, hasMore, items.length, rows.at(-1)?.index, state.loading, state.error]);
  // Closing a menu does not cancel a new context read owned by StudioApp.
  return <>
    <SearchField aria-label={t.searchLoadedFolders} value={search} onChange={value=>{setSearch(value);scroll.current?.scrollTo(0,0);}} variant="secondary"><SearchField.Group><SearchField.SearchIcon/><SearchField.Input placeholder={t.searchLoadedFolders}/><SearchField.ClearButton/><Button variant="ghost" isIconOnly size="sm" aria-label={t.back} isDisabled={!state.windowed} onPress={()=>{client?.resetAncestors();scroll.current?.scrollTo(0,0);}}>…</Button></SearchField.Group></SearchField>
    <ScrollShadow ref={scroll} hideScrollBar className="fms-ancestor-scroll" style={{height}}>
      {!items.length && search && <p className="fms-sidebar-folder-empty">{t.noFolders}</p>}
      <div style={{height: virtual.getTotalSize(), position: 'relative'}}>{rows.map(row => {
        const folder = items[row.index];
        return <div key={row.key} data-ancestor-key={folder?.id} style={{position: 'absolute', top: row.start, height: row.size, insetInline: 4}}>{folder ? <Button variant="ghost" className="fms-ancestor-option" onPress={() => onFolder(folder)}><span title={folder.name}>{folder.name}</span></Button> : state.error ? <Button variant="ghost" onPress={() => void client?.loadMore()}>{t.retry}</Button> : <Spinner size="sm" aria-label={t.loading}/>}</div>;
      })}</div>
    </ScrollShadow>
  </>;
}
