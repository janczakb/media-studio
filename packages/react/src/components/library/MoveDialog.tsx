import {captureScrollAnchor,restoreScrollAnchor,type ScrollAnchor} from '../../folders/scroll-anchor';
import {Button,InputGroup,Modal,SearchField,Spinner} from '@heroui/react';
import {ArrowDown01Icon,ArrowUp01Icon,Folder01Icon,Tick02Icon,Search01Icon,XIcon} from '@hugeicons/core-free-icons';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect,useMemo,useRef,useState,useSyncExternalStore,useCallback,useLayoutEffect} from 'react';
import {FolderTreeClient,type FolderSummary} from '../../folders/FolderTreeClient';
import type {Entry,StudioTransport} from '../../contracts';
import {Icon,errorText,type Messages} from '../../ui';
import {CreateDestinationDialog} from './CreateDestinationDialog';

type DialogContext = {workspace:string;transport:StudioTransport;portal:HTMLDivElement;t:Messages;onClose:()=>void};
type Destination = {id:string|null;name:string;canWrite:boolean;path:string[]};
const ROW_HEIGHT=60;

export function MoveDialog({entry,onChanged,submit,...context}:DialogContext&{entry:Entry|null;onChanged:(entry:Entry)=>void;submit?:(entry:Entry,parent:string|null,signal:AbortSignal)=>Promise<void>}) {
  if(!entry)return null;
  return <DestinationDialog key={entry.id} {...context} excludedIds={[entry.id]} submit={async(parent,signal)=>{
    if(submit){await submit(entry,parent,signal);return;}
    const updated=await context.transport.update(context.workspace,entry.id,{revision:entry.revision,parent},signal);
    if(!signal.aborted)onChanged(updated);
  }}/>;
}

export function DestinationDialog({workspace,transport,portal,t,onClose,excludedIds,submit}:DialogContext&{
  excludedIds:readonly string[];submit:(parent:string|null,signal:AbortSignal)=>Promise<void>;
}) {
  const [search,setSearch]=useState(''),[query,setQuery]=useState(''),[selected,setSelected]=useState<Destination|null>(null);
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[creating,setCreating]=useState<Destination|null>(null),[revision,setRevision]=useState(0);
  const mutation=useRef<AbortController|null>(null);
  const [overlays,setOverlays]=useState<HTMLDivElement|null>(null);
  useEffect(()=>{if(search.trim()===query)return;const timer=setTimeout(()=>{setQuery(search.trim());setSelected(null);},200);return()=>clearTimeout(timer);},[search,query]);
  useEffect(()=>()=>mutation.current?.abort(),[]);
  const run=(action:(signal:AbortSignal)=>Promise<void>)=>{
    if(mutation.current)return;
    const controller=new AbortController();mutation.current=controller;setBusy(true);setError('');
    void action(controller.signal).catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{mutation.current=null;if(!controller.signal.aborted)setBusy(false);});
  };
  return <Modal><Modal.Backdrop isOpen isDismissable={!creating} onOpenChange={open=>{if(!open&&!mutation.current)onClose();}} UNSTABLE_portalContainer={portal} className="fms-move-backdrop">
    <Modal.Container className="fms-move-container" size="lg" placement="center"><Modal.Dialog className="fms-move-dialog" aria-label={t.moveTo}>
      <Modal.Header className="fms-move-header"><Modal.Heading>{t.moveTo}</Modal.Heading><div className="fms-move-header-actions">
        <Button variant="tertiary" isDisabled={busy||selected?.canWrite===false} onPress={()=>setCreating(selected??{id:null,name:t.rootFolder,canWrite:true,path:[]})}>{selected?.id?t.createSubSpace:t.createSpace}</Button>
        <Button variant="tertiary" isIconOnly aria-label={t.close} isDisabled={busy} onPress={onClose}><Icon icon={XIcon} size={24}/></Button>
      </div></Modal.Header>
      <div className="fms-move-search"><SearchField aria-label={t.findFolder} value={search} onChange={setSearch} isDisabled={busy} fullWidth>
        <InputGroup variant="secondary"><InputGroup.Prefix><Icon icon={Search01Icon} size={20}/></InputGroup.Prefix><InputGroup.Input placeholder={t.findFolder} maxLength={100}/><InputGroup.Suffix><SearchField.ClearButton aria-label={t.clearSearch}/></InputGroup.Suffix></InputGroup>
      </SearchField></div>
      <FolderTree key={`${workspace}:${query}:${revision}`} workspace={workspace} transport={transport} query={query} excludedIds={excludedIds} selected={selected} onSelect={setSelected} busy={busy} t={t}/>
      {error&&<p className="fms-error fms-move-error" role="alert">{error}</p>}
      <Modal.Footer className="fms-move-footer"><Button isDisabled={busy||!selected?.canWrite} onPress={()=>{if(selected)run(async signal=>{await submit(selected.id,signal);if(!signal.aborted)onClose();});}}>{busy?<Spinner size="sm"/>:t.move}</Button></Modal.Footer>
      <div ref={setOverlays}/>
      {creating&&overlays&&<CreateDestinationDialog workspace={workspace} transport={transport} portal={overlays} t={t} parent={creating.id} path={creating.path} onClose={()=>setCreating(null)} onCreated={()=>{setCreating(null);setRevision(value=>value+1);}}/>}
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}

function FolderTree({workspace,transport,query,excludedIds,selected,onSelect,busy,t}:{workspace:string;transport:StudioTransport;query:string;excludedIds:readonly string[];selected:Destination|null;onSelect:(value:Destination|null)=>void;busy:boolean;t:Messages}) {
  const scroll=useRef<HTMLDivElement|null>(null);
  const client=useMemo(()=>new FolderTreeClient(workspace,(id,input,cursor,signal)=>transport.browse(id,{...input,search:query},cursor,signal)),[workspace,transport,query]);
  const anchor=useRef<ScrollAnchor|null>(null);
  const subscribe=useCallback((notify:()=>void)=>client.subscribe(()=>{anchor.current??=captureScrollAnchor(scroll.current,'[data-folder-key]','data-folder-key');notify();}),[client]);
  const snapshot=useSyncExternalStore(subscribe,client.getSnapshot,client.getSnapshot);
  useEffect(()=>{client.toggle(null);return()=>client.suspend();},[client]);
  const rows=useMemo(()=>snapshot.rows.filter(row=>!row.folder||!excludedIds.includes(row.folder.id)),[snapshot.rows,excludedIds]);
  const virtual=useVirtualizer({count:rows.length,getScrollElement:()=>scroll.current,estimateSize:()=>ROW_HEIGHT,overscan:4,getItemKey:index=>rows[index]?.key??index});
  const visible=virtual.getVirtualItems();
  const visibleKey=visible.map(item=>item.key).join('|');
  useLayoutEffect(()=>{restoreScrollAnchor(scroll.current,rows.map(row=>row.key),ROW_HEIGHT,anchor.current);anchor.current=null;},[snapshot.version,rows]);
  useEffect(()=>{
    const viewport=scroll.current;
    if(!viewport)return;
    const first=Math.max(0,Math.floor(viewport.scrollTop/ROW_HEIGHT)-4);
    const last=Math.ceil((viewport.scrollTop+viewport.clientHeight)/ROW_HEIGHT)+4;
    client.loadVisible(rows.slice(first,last));
  },[client,snapshot.version,visibleKey,rows]);
  const root=snapshot.root;
  const rootId=root?.id??null;
  const open=snapshot.expanded.has(rootId);
  const select=(entry:FolderSummary|null)=>{
    const id=entry?.id??null;
    const path=entry?client.pathTo(entry).map(folder=>folder.name):[];
    onSelect(selected?.id===id?null:{id,name:entry?.name??t.rootFolder,canWrite:snapshot.canWrite&&entry?.readOnly!==true,path});
  };
  return <div className="fms-move-tree-wrap">
    {root&&<Button variant="ghost" className="fms-move-context-back" onPress={()=>{client.reveal([]);}}><Icon icon={ArrowUp01Icon}/>{t.rootFolder}</Button>}
    <div className="fms-move-root fms-move-row" onClick={event=>{if(!(event.target as Element).closest('button')&&!busy&&snapshot.canWrite)select(root);}}>
      <DestinationCheckbox label={root?.name??t.rootFolder} selected={selected?.id===rootId} disabled={busy||!snapshot.canWrite||root?.readOnly===true} onPress={()=>select(root)}/>
      <Icon icon={Folder01Icon} size={22}/><span>{root?.name??t.rootFolder}</span>
      <Button isIconOnly variant="ghost" aria-label={root?.name??t.rootFolder} aria-expanded={open} onPress={()=>client.toggle(rootId)}><Icon icon={open?ArrowUp01Icon:ArrowDown01Icon} size={24}/></Button>
    </div>
    <div ref={scroll} className="fms-move-tree" aria-label={t.selectFolder}>
      <div style={{height:virtual.getTotalSize(),position:'relative'}}>{visible.map(item=>{
        const row=rows[item.index]!,entry=row.folder;
        const disabled=busy||entry?.readOnly===true||!snapshot.canWrite;
        return <div key={row.key} data-folder-key={entry?row.key:undefined} className="fms-move-row" style={{position:'absolute',insetInline:0,top:item.start,height:ROW_HEIGHT,paddingInlineStart:20+Math.min(row.depth,8)*20}} onClick={event=>{if(entry&&!(event.target as Element).closest('button')&&!disabled)select(entry);}}>
          {entry?<><DestinationCheckbox label={entry.name} selected={selected?.id===entry.id} disabled={disabled} onPress={()=>select(entry)}/><Icon icon={Folder01Icon} size={22}/><button type="button" className="fms-move-folder-name" title={entry.name} disabled={disabled} onClick={()=>select(entry)}>{entry.name}</button>
            {entry.hasChildFolders!==false&&<Button isIconOnly variant="ghost" aria-label={entry.name} aria-expanded={snapshot.expanded.has(entry.id)} isDisabled={busy} onPress={()=>client.toggle(entry.id,entry,row.parent)}><Icon icon={snapshot.expanded.has(entry.id)?ArrowUp01Icon:ArrowDown01Icon} size={24}/></Button>}
          </>:row.error?<div className="fms-move-load-error" role="alert"><span>{row.error}</span><Button size="sm" variant="ghost" onPress={()=>client.retry(row)}>{t.retry}</Button></div>:<Spinner size="sm" aria-label={t.loading}/>}
        </div>;
      })}</div>
      {open&&rows.length===0&&<p className="fms-move-empty">{t.noFolders}</p>}
    </div>
  </div>;
}

function DestinationCheckbox({label,selected,disabled,onPress}:{label:string;selected:boolean;disabled:boolean;onPress:()=>void}) {
  return <button type="button" className="fms-move-checkbox" role="checkbox" aria-label={label} aria-checked={selected} disabled={disabled} onClick={onPress}>{selected&&<Icon icon={Tick02Icon} size={14}/>}</button>;
}
