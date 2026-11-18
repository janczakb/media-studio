import {fileNameParts} from '../../file-caption';
import {Button} from '@heroui/react';
import {File01Icon,Folder01Icon,RestoreBinIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {Entry} from '../../contracts';
import type {ManagementTransport,TrashPage as TrashResult,TrashRecord,TrashQuery} from '../../management-contracts';
import {Icon,errorText,formatBytes} from '../../ui';
import {ConfirmDialog,CursorFooter,ListState,ManagementSelect,ManagementTabs,PageFrame,VirtualRows,DateText,useManagementMessages,useCursorPage,type ManagementLocale} from './shared';
export function TrashPage({workspace,transport,portal,locale='en',onOpen,onRestored}:{workspace:string;transport:ManagementTransport;portal:HTMLElement;locale?:ManagementLocale;onOpen?:(id:string)=>void;onRestored?:(entry:Entry)=>void}) {
  const t=useManagementMessages(),[tab,setTab]=useState<'deleted'|'restorations'>('deleted'),[kind,setKind]=useState('all'),[actor,setActor]=useState<'all'|'me'>('all'),[period,setPeriod]=useState('all');
  const [selection,setSelection]=useState<TrashRecord|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const operation=useRef<AbortController|null>(null);
  useEffect(()=>()=>operation.current?.abort(),[workspace]);
  const [filterDay] = useState(()=>new Date().toISOString().slice(0,10));
  const identity=JSON.stringify([workspace,tab,kind,actor,period]);
  const page=useCursorPage<TrashRecord,TrashResult>(transport.trashReport?(cursor,signal)=>{
    const after=period==='all'?undefined:new Date(Date.parse(filterDay)-Number(period)*86400000).toISOString();
    const query:TrashQuery={tab:tab==='restorations'?'restorations':kind==='files'?'files':kind==='folders'?'folders':'all',actor,...(after?{after}:{})};
    return transport.trashReport!(workspace,query,cursor,signal);
  }:undefined,identity);
  const restore=async()=>{if(!selection||!transport.restoreEntry||busy)return;const controller=new AbortController();operation.current=controller;setBusy(true);setError('');try{const entry=await transport.restoreEntry(workspace,selection.id,selection.revision,controller.signal);if(!controller.signal.aborted){setSelection(null);page.refresh();onRestored?.(entry);}}catch(reason){if(!controller.signal.aborted)setError(errorText(reason));}finally{if(!controller.signal.aborted)setBusy(false);}};
  return <PageFrame title={t.trash} hint={page.data?(page.data.retention.enabled?t.retention.replace('{days}',String(page.data.retention.days)):t.retentionOff):t.trashHint}>
    <div className="fms-management-tabs"><ManagementTabs label={t.trash} value={tab} options={[["deleted",t.all],["restorations",t.restorations]]} onChange={value=>setTab(value as typeof tab)}/></div>
    <div className="fms-management-filters">{tab==='deleted'&&<ManagementSelect label={t.allItems} value={kind} options={[["all",t.allItems],["files",t.files],["folders",t.folders]]} onChange={setKind} portal={portal}/>}<ManagementSelect label={tab==='restorations'?t.restoredBy:t.deletedBy} value={actor} options={[["all",t.anyone],["me",t.me]]} onChange={value=>setActor(value as typeof actor)} portal={portal}/><ManagementSelect label={t.date} value={period} options={[["all",t.allDates],["7",t.last7],["30",t.last30]]} onChange={setPeriod} portal={portal}/></div>
    <div className="fms-management-columns"><span>{t.name}</span><span>{t.size}</span><span>{tab==='restorations'?t.restoredOn:t.deletedOn}</span><span/></div>
    {!page.data||!page.data.items.length?<ListState kind="trash" pending={page.pending} error={page.error} empty={tab==='restorations'?t.restorationEmpty:t.trashEmpty} available={page.available} retry={page.refresh} t={t}/>:<VirtualRows items={page.data.items} identity={identity+page.cursor} getKey={item=>item.activityId??item.id} render={item=><>
      <div className="fms-management-identity"><Icon icon={item.kind==='folder'?Folder01Icon:File01Icon}/><div><strong>{fileNameParts({...item, mediaType: null}).title}</strong>{item.purging?<small>{t.purging}</small>:item.available===false&&<small>{t.missing}</small>}</div></div><span>{item.kind==='folder'?'—':formatBytes(item.size)}</span><time dateTime={item.occurredAt}><DateText value={item.occurredAt} locale={locale}/></time>
      <div>{tab==='deleted'&&item.canRestore===true&&transport.restoreEntry?<Button size="sm" variant="tertiary" aria-label={`${t.restore}: ${item.name}`} onPress={()=>{setSelection(item);setError('');}}><Icon icon={RestoreBinIcon} size={16}/>{t.restore}</Button>:tab==='restorations'&&item.available&&onOpen?<Button size="sm" variant="tertiary" onPress={()=>onOpen(item.id)}>{t.openFile}</Button>:null}</div>
    </>}/>}
    <CursorFooter pageNumber={page.pageNumber} previous={page.data?.previousCursor??null} next={page.data?.nextCursor??null} busy={page.pending} onPage={page.setCursor} refresh={page.refresh} t={t}/>
    {selection&&<ConfirmDialog portal={portal} title={t.restoreTitle} hint={`${selection.name} — ${t.restoreHint}`} confirm={t.restore} busy={busy} error={error} onConfirm={()=>void restore()} onClose={()=>setSelection(null)} t={t}/>}
  </PageFrame>;
}
