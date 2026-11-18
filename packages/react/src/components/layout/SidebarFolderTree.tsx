import { Button, ScrollShadow } from '@heroui/react';
import { ArrowRight01Icon, Folder01Icon } from '@hugeicons/core-free-icons';
import { useVirtualizer } from '@tanstack/react-virtual';
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { FolderSummary, FolderTreeClient } from '../../folders/FolderTreeClient';
import { Icon, type Messages } from '../../ui';
const ROW_HEIGHT = 40;
const ITEM_HEIGHT = 36;
const PREFETCH_ROWS = 10;
export function SidebarFolderTree({ client, path, collapsed, active, selected, preferenceKey, t, onRoot, onFolder, refreshOnFocus = true }: {
    client: FolderTreeClient;
    refreshOnFocus?: boolean;
    path: readonly FolderSummary[];
    collapsed?: boolean;
    active: boolean;
    selected: string | null;
    preferenceKey: string;
    t: Messages;
    onRoot: () => void;
    onFolder: (folder: FolderSummary) => void;
}) {
    const scroll = useRef<HTMLDivElement>(null);
    const container = useRef<HTMLDivElement>(null);
    const storageKey = preferenceKey + '.folders.' + client.workspace;
    const [initialLayout] = useState(() => {
        try {
            const height = Number(localStorage.getItem(storageKey + '.height'));
            if (Number.isFinite(height) && height >= 36 && height <= 2048) return {height, cached:true};
        } catch {}
        return {height:160,cached:false};
    });
    const [focused,setFocused] = useState<string|null>(null);
    const [settled,setSettled] = useState(false);
    const [windowRows,setWindowRows] = useState(0);
    const id = useId();
    const anchor = useRef<{key:string;offset:number;scrollTop:number}|null>(null);
    const revealed = useRef<string|null>(null);
    const manualSelection = useRef<string|null>(null);
    const expansionMotion = useRef<{parent:string|null}|null>(null);
    const navigationTarget = useRef<string|null>(null);
    navigationTarget.current=active?selected:null;
    const animations = useRef(new Set<Animation>());
    // One authoritative snapshot. No delayed projection, scrolling timer or
    // independent animation state is allowed to reorder the rendered outline.
    const subscribe = useCallback((notify:()=>void)=>client.subscribe(()=>{
        const viewport=scroll.current;
        if(viewport && !anchor.current){
            const top=viewport.getBoundingClientRect().top;
            const bottom=top+viewport.clientHeight;
            const candidates=[...viewport.querySelectorAll<HTMLElement>('[role="treeitem"][data-folder-key]')].filter(element=>{
                const rect=element.getBoundingClientRect();
                return rect.bottom>top && rect.top<bottom;
            });
            const destination=navigationTarget.current;
            const row=(destination&&manualSelection.current!==destination?candidates.find(row=>row.dataset.folderKey===destination):null)??candidates[0];
            if(row?.dataset.folderKey)anchor.current={key:row.dataset.folderKey,offset:row.getBoundingClientRect().top-top,scrollTop:viewport.scrollTop};
        }
        notify();
    }),[client]);
    const snapshot=useSyncExternalStore(subscribe,client.getSnapshot,client.getSnapshot);
    const virtual=useVirtualizer({count:snapshot.rows.length,getScrollElement:()=>scroll.current,estimateSize:()=>ROW_HEIGHT,overscan:4,getItemKey:index=>snapshot.rows[index]?.key??index});
    const visible=virtual.getVirtualItems();
    const visibleKey=visible.map(row=>row.key).join('|');
    const pathKey=JSON.stringify(path);
    const expandedKey=JSON.stringify([...snapshot.expanded]);
    const awaitingPath=active&&!!selected&&(path.at(-1)?.id!==selected || snapshot.root?.id!==selected&&!snapshot.rows.some(row=>row.folder?.id===selected));
    const initializing=!settled;

    useLayoutEffect(()=>{
        const nav=container.current?.closest('.fms-context-nav');
        const footer=nav?.querySelector('.fms-sidebar-footer');
        const measure=()=>setWindowRows(Math.max(1,Math.ceil(((nav?.clientHeight??container.current?.clientHeight??160)-(footer?.clientHeight??0)-44)/ROW_HEIGHT)));
        const observer=new ResizeObserver(measure);
        if(nav)observer.observe(nav);
        if(footer)observer.observe(footer);
        measure();
        return()=>observer.disconnect();
    },[]);
    useLayoutEffect(()=>{
        try{
            const saved=JSON.parse(localStorage.getItem(storageKey)??'[]');
            if(Array.isArray(saved))client.restore(saved);
        }catch{}
        return()=>client.suspend();
    },[client,storageKey]);
    useLayoutEffect(()=>{
        if(active && (!selected||path.at(-1)?.id===selected))client.reveal(path,collapsed);
    },[client,active,selected,collapsed,pathKey]);
    useEffect(()=>{
        if(snapshot.version===0||expandedKey!==JSON.stringify([...client.getSnapshot().expanded]))return;
        try{localStorage.setItem(storageKey,expandedKey);}catch{}
    },[client,storageKey,expandedKey]);

    // Restore one real row at its exact pixel offset. Only write scrollTop when
    // rows were inserted/removed above it; ordinary data/status updates do nothing.
    useLayoutEffect(()=>{
        const saved=anchor.current,viewport=scroll.current;
        anchor.current=null;
        if(saved&&viewport){
            const index=snapshot.rows.findIndex(row=>row.key===saved.key);
            if(index>=0){
                const target=Math.max(0,index*ROW_HEIGHT-saved.offset+(viewport.scrollTop-saved.scrollTop));
                if(Math.abs(target-viewport.scrollTop)>.5)viewport.scrollTop=target;
            }
        }
    },[snapshot.version]);
    // Selection reveals once per navigation. Subsequent fetches use the same
    // anchor as manual scrolling, never a second competing scroll-to-selection.
    useLayoutEffect(()=>{
        if(!active||!selected){revealed.current=null;return;}
        if(awaitingPath||manualSelection.current===selected||settled&&revealed.current===selected)return;
        const index=snapshot.rows.findIndex(row=>row.folder?.id===selected);
        if(index>=0){virtual.scrollToIndex(index,{align:'start'});if(settled)revealed.current=selected;}
    },[active,selected,awaitingPath,settled,snapshot.version,virtual]);
    useLayoutEffect(()=>{
        const viewport=scroll.current;
        if(awaitingPath){client.loadVisible([]);return;}
        if(!viewport){
            client.loadVisible([]);
            if(windowRows>0&&snapshot.version>0)setSettled(true);
            return;
        }
        const first=Math.max(0,Math.floor(viewport.scrollTop/ROW_HEIGHT));
        const count=Math.max(windowRows,Math.ceil(viewport.clientHeight/ROW_HEIGHT));
        const start=manualSelection.current===selected?Math.max(0,first-PREFETCH_ROWS):first;
        const current=snapshot.rows.slice(first,first+count);
        const selectedRow=snapshot.rows.find(row=>row.folder?.id===selected);
        const ready=!snapshot.restoring&&windowRows>0&&(!snapshot.rows.length||current.length>0)&&client.areRowsReady(current)&&(!selectedRow||client.areRowsReady([selectedRow]));
        // Admission follows anchoring in this same layout phase. The cold outline
        // remains hidden until its complete destination window is ready.
        const destination=selected?snapshot.rows.findIndex(row=>row.folder?.id===selected):-1;
        const requested=snapshot.rows.slice(start,first+count+PREFETCH_ROWS).filter((row,index)=>
            // A nearby sentinel from an earlier, expanded sibling must not keep
            // inserting pages ahead of a destination the user just navigated to.
            !row.edge||manualSelection.current===selected||destination<0||start+index>=destination);
        client.loadVisible(requested);
        if(!settled&&ready)setSettled(true);
    },[client,awaitingPath,windowRows,settled,selected,snapshot.version,visibleKey]);
    useLayoutEffect(()=>{
        const node=container.current;
        if(!settled||!node)return;
        const save=()=>{try{const height=Math.round(node.getBoundingClientRect().height);if(height>=36&&height<=2048)localStorage.setItem(storageKey+'.height',String(height));}catch{}};
        const observer=new ResizeObserver(save);observer.observe(node);save();
        return()=>observer.disconnect();
    },[settled,storageKey]);
    useEffect(()=>{
        if(!refreshOnFocus)return;
        const resume=()=>{if(document.visibilityState==='visible')client.refresh();};
        window.addEventListener('focus',resume);
        return()=>window.removeEventListener('focus',resume);
    },[client,refreshOnFocus]);
    useEffect(()=>()=>{for(const animation of animations.current)animation.cancel();animations.current.clear();},[client]);
    useLayoutEffect(()=>{
        const intent=expansionMotion.current,viewport=scroll.current;
        if(!intent||!settled||!viewport)return;
        const children=snapshot.rows.filter(row=>row.parent===intent.parent&&row.folder);
        if(!children.length)return;
        expansionMotion.current=null;
        if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
        for(const row of viewport.querySelectorAll<HTMLElement>('[data-folder-key]')){
            if(!children.some(child=>child.key===row.dataset.folderKey))continue;
            const content=row.querySelector<HTMLElement>('.fms-folder-row-content');
            if(!content?.animate)continue;
            const animation=content.animate([{opacity:.5},{opacity:1}],{duration:120,easing:'ease-out'});
            animations.current.add(animation);
            void animation.finished.then(()=>animations.current.delete(animation),()=>animations.current.delete(animation));
        }
    },[settled,snapshot.version]);
    const takeScrollControl=()=>{manualSelection.current=selected;expansionMotion.current=null;};
    const toggleBranch=(folderId:string|null,folder?:FolderSummary,parent:string|null=null)=>{
        expansionMotion.current=snapshot.expanded.has(folderId)?null:{parent:folderId};
        client.toggle(folderId,folder,parent);
    };
    const chooseVisibleFolder=(folder:FolderSummary)=>{
        manualSelection.current=folder.id;
        revealed.current=folder.id;
        onFolder(folder);
    };
    const focusRow = (index: number) => {
        const row = snapshot.rows[index];
        if (!row?.folder)
            return;
        setFocused(row.key);
        virtual.scrollToIndex(index, { align: 'auto' });
        scroll.current?.focus();
    };
    const keyboard = (event: React.KeyboardEvent) => {
        const rows = snapshot.rows;
        let index = rows.findIndex(row => row.key === focused);
        if (index < 0)
            index = rows.findIndex(row => !!row.folder);
        const row = rows[index];
        if (!row?.folder)
            return;
        const rtl = getComputedStyle(scroll.current!).direction === 'rtl';
        const expand = event.key === (rtl ? 'ArrowLeft' : 'ArrowRight'), collapse = event.key === (rtl ? 'ArrowRight' : 'ArrowLeft');
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const forward = event.key === 'ArrowDown' || event.key === 'Home';
            let next = event.key === 'Home' ? 0 : event.key === 'End' ? rows.length - 1 : index + (forward ? 1 : -1);
            while (next >= 0 && next < rows.length && !rows[next]?.folder)
                next += forward ? 1 : -1;
            focusRow(next);
        }
        else if (expand) {
            event.preventDefault();
            if (row.folder.hasChildFolders !== false && !snapshot.expanded.has(row.folder.id))
                toggleBranch(row.folder.id, row.folder, row.parent);
            else if (rows[index + 1]?.depth === row.depth + 1)
                focusRow(index + 1);
        }
        else if (collapse) {
            event.preventDefault();
            if (snapshot.expanded.has(row.folder.id))
                toggleBranch(row.folder.id);
            else
                focusRow(rows.findIndex(value => value.folder?.id === row.parent));
        }
        else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            chooseVisibleFolder(row.folder);
        }
    };
    const rootId = snapshot.root?.id ?? null;
    const open = snapshot.expanded.has(rootId);
    return <div ref={container} className="fms-sidebar-folders" style={initializing?{blockSize:initialLayout.height}:undefined} data-cached-layout={initialLayout.cached||undefined} data-initializing={initializing||undefined} aria-busy={initializing}><div className="fms-folder-tree-content" inert={initializing}>
    <div className="fms-folder-root">
      <Button variant="ghost" className={`fms-nav${active && selected === null ? ' is-active' : ''}`} aria-label={t.files} aria-current={active && selected === null ? 'page' : 'false'} onPress={onRoot}><Icon icon={Folder01Icon}/><span>{t.files}</span></Button>
      {!snapshot.root&&<Button variant="ghost" isIconOnly className="fms-folder-disclosure" aria-label={`${t.folder}: ${t.files}`} aria-expanded={open} onPress={() => toggleBranch(rootId)}><Icon icon={ArrowRight01Icon} size={20}/></Button>}
    </div>
    {snapshot.root && <div className="fms-folder-context-root"><Button variant="ghost" className="fms-nav" onPress={() => {const parent = client.parentOf(snapshot.root!); if (parent) onFolder(parent); else onRoot();}}><span className="fms-folder-parent-icon"><Icon icon={ArrowRight01Icon}/></span><span title={client.parentOf(snapshot.root)?.name}>{client.parentOf(snapshot.root)?.name ?? t.files}</span></Button><div className="fms-folder-root fms-folder-context-current"><Button variant="ghost" className={`fms-nav${active&&selected===snapshot.root.id?' is-active':''}`} aria-current={active&&selected===snapshot.root.id?'page':'false'} onPress={()=>onFolder(snapshot.root!)}><Icon icon={Folder01Icon}/><span title={snapshot.root.name}>{snapshot.root.name}</span></Button>{snapshot.rows.length>0&&<Button variant="ghost" isIconOnly className="fms-folder-disclosure" aria-label={`${t.folder}: ${snapshot.root.name}`} aria-expanded={open} onPress={()=>toggleBranch(rootId)}><Icon icon={ArrowRight01Icon} size={20}/></Button>}</div></div>}
    {open && <ScrollShadow hideScrollBar size={16} ref={scroll} className="fms-sidebar-folder-tree" style={{blockSize: snapshot.rows.length ? snapshot.rows.length*ROW_HEIGHT : 36}} onWheelCapture={takeScrollControl} onTouchStartCapture={takeScrollControl} onTouchMoveCapture={takeScrollControl} onPointerDownCapture={takeScrollControl} onKeyDownCapture={takeScrollControl} role="tree" aria-label={t.folder} tabIndex={0} aria-activedescendant={focused && snapshot.rows.some(row => row.key === focused) ? id + '-' + encodeURIComponent(focused) : undefined} onKeyDown={keyboard}>
      <div className="fms-sidebar-folder-canvas" style={{ height: virtual.getTotalSize() }}>
        {visible.map(item => {
                const row = snapshot.rows[item.index]!;
                return <div key={row.key} id={id + '-' + encodeURIComponent(row.key)} data-folder-key={row.key} data-folder-parent={row.parent??''} data-folder-page={row.page} className={`fms-sidebar-folder-row${active && selected === row.folder?.id ? ' is-active' : ''}`} style={{ top: item.start, height: ITEM_HEIGHT, insetInlineStart: Math.min(row.depth, 6) * 14, paddingInlineStart: 0 }} role={row.folder ? 'treeitem' : undefined} aria-level={row.folder ? row.depth : undefined} aria-selected={row.folder ? active && selected === row.folder.id : undefined} aria-expanded={row.folder && row.folder.hasChildFolders !== false ? snapshot.expanded.has(row.folder.id) : undefined} data-tree-focus={focused === row.key} data-folder-workspace={client.workspace} data-move-folder={row.folder && !row.folder.readOnly ? row.folder.id : undefined} data-folder-expandable={row.folder?.hasChildFolders !== false && !!row.folder}>
            <div className="fms-folder-row-content">{row.folder ? <>
              <Button variant="ghost" className="fms-nav fms-sidebar-folder-name" excludeFromTabOrder onPress={() => { setFocused(row.key); chooseVisibleFolder(row.folder!); }}><Icon icon={Folder01Icon} size={20}/><span title={row.folder.name}>{row.folder.name}</span></Button>
              {row.folder.hasChildFolders !== false && <Button variant="ghost" isIconOnly excludeFromTabOrder className="fms-folder-disclosure" aria-label={row.folder.name} aria-expanded={snapshot.expanded.has(row.folder.id)} onPress={() => { setFocused(row.key); toggleBranch(row.folder!.id, row.folder!, row.parent); }}><Icon icon={ArrowRight01Icon} size={20}/></Button>}
            </> : row.error ? <Button size="sm" variant="ghost" onPress={() => client.retry(row)}>{t.retry}</Button> : <span className="fms-folder-row-loading" role="status" aria-label={t.loading}><span className="fms-folder-loading-icon"/><span className="fms-folder-loading-label"/></span>}</div>
          </div>;
            })}
      </div>
      {!snapshot.rows.length && <p className="fms-sidebar-folder-empty">{t.noFolders}</p>}
    </ScrollShadow>}
  </div>{initializing&&<div className="fms-folder-tree-placeholder" aria-hidden="true">{[0,1,2].map(index=><span key={index}/>)}</div>}</div>;
}
