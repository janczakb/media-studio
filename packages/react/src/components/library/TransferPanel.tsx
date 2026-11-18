import {Button,ProgressBar,Tabs} from '@heroui/react';
import {AlertCircleIcon,ArrowDown01Icon,ArrowUp01Icon,Cancel01Icon,CheckmarkCircle03Icon,File01Icon,PauseIcon,PlayIcon,RefreshIcon,Upload04Icon} from '@hugeicons/core-free-icons';
import {useVirtualizer} from '@tanstack/react-virtual';
import {memo,useCallback,useMemo,useRef,useState,useSyncExternalStore} from 'react';
import type {TransferItem,TransferQueue} from '../../uploads';
import {Icon,formatBytes,type Messages} from '../../ui';

type TransferFilter = 'all' | 'completed' | 'failed';

export function TransferPanel({queue,t,initiallyExpanded=true}:{queue:TransferQueue;t:Messages;initiallyExpanded?:boolean}) {
  const snapshot = useSyncExternalStore(queue.subscribe,queue.getSnapshot,queue.getSnapshot);
  const [expanded,setExpanded] = useState(initiallyExpanded);
  const [filter,setFilter] = useState<TransferFilter>('all');
  const filters = [['all',t.allTransfers],['completed',t.completedTransfers],['failed',t.failedTransfers]] as const;
  const ids = useMemo(()=>{
    if(filter==='all')return snapshot.ids;
    return snapshot.ids.filter(id=>queue.getItem(id)?.state===(filter==='completed'?'completed':'error'));
  },[filter,snapshot.ids,snapshot.completedCount,snapshot.failedCount,queue]);
  if(snapshot.ids.length===0)return null;
  const hasPending = snapshot.pendingCount>0;
  const hasFinished = snapshot.ids.length>snapshot.pendingCount+snapshot.failedCount;
  return <aside className={`fms-transfer-panel${expanded?'':' is-collapsed'}`} aria-label={t.uploads}>
    <header>
      <h2>{t.uploads}</h2>
      <div>
        {(snapshot.pendingCount>0||snapshot.isPaused)&&<Button isIconOnly size="sm" variant="ghost" aria-label={snapshot.isPaused?t.resumeAllUploads:t.pauseAllUploads} onPress={()=>snapshot.isPaused?queue.resumeAll():queue.pauseAll()}><Icon icon={snapshot.isPaused?PlayIcon:PauseIcon} size={16}/></Button>}
        {expanded&&snapshot.pendingCount>0&&<Button size="sm" variant="ghost" onPress={()=>{for(const id of snapshot.ids)queue.cancel(id);}}>{t.cancelAllUploads}</Button>}
        <Button isIconOnly size="sm" variant="ghost" aria-label={expanded?t.collapseUploads:t.expandUploads} aria-expanded={expanded}
          onPress={()=>setExpanded(value=>!value)}><Icon icon={expanded?ArrowDown01Icon:ArrowUp01Icon}/></Button>
      </div>
    </header>
    {expanded&&<>
      <Tabs selectedKey={filter} onSelectionChange={key=>{
        if(key==='all'||key==='completed'||key==='failed')setFilter(key);
      }} variant="secondary" className="fms-transfer-tabs">
        <Tabs.ListContainer className="fms-transfer-tablist">
          <Tabs.List aria-label={t.uploads}>{filters.map(([id,label])=><Tabs.Tab key={id} id={id}>
            {label}<Tabs.Indicator/>
          </Tabs.Tab>)}</Tabs.List>
        </Tabs.ListContainer>
        {filters.map(([id])=><Tabs.Panel key={id} id={id}>
          {filter===id&&<TransferList ids={ids} queue={queue} t={t} allPaused={snapshot.isPaused}/>}
        </Tabs.Panel>)}
      </Tabs>
      {(hasPending||hasFinished)&&<footer>
        {hasPending&&<div><Icon icon={Upload04Icon} size={15}/><span>{formatBytes(snapshot.loadedBytes)} / {formatBytes(snapshot.totalBytes)}</span></div>}
        {hasFinished&&<Button variant="ghost" size="sm" onPress={queue.clearFinished}>{t.clearFinished}</Button>}
      </footer>}
    </>}
  </aside>;
}

function TransferList({ids,queue,t,allPaused}:{ids:readonly string[];queue:TransferQueue;t:Messages;allPaused:boolean}) {
  const scroll = useRef<HTMLDivElement>(null);
  const rowHeight = 64;
  const virtual = useVirtualizer({
    count:ids.length, getScrollElement:()=>scroll.current, estimateSize:()=>rowHeight,
    overscan:3, getItemKey:index=>ids[index]??index,
  });

  return <div ref={scroll} className="fms-transfer-scroll" role="list" aria-label={t.uploads}>
    {ids.length===0?<p className="fms-transfer-empty">{t.transfersEmpty}</p>:<div style={{height:virtual.getTotalSize(),position:'relative'}}>
      {virtual.getVirtualItems().map(row=><div key={row.key} role="listitem" aria-posinset={row.index+1} aria-setsize={ids.length}
        style={{position:'absolute',top:0,left:0,right:0,height:rowHeight,transform:`translateY(${row.start}px)`}}>
        <TransferRow id={ids[row.index]!} queue={queue} t={t} allPaused={allPaused}/>
      </div>)}
    </div>}
  </div>;
}

const TransferRow=memo(function TransferRow({id,queue,t,allPaused}:{id:string;queue:TransferQueue;t:Messages;allPaused:boolean}) {
  const subscribe=useCallback((notify:()=>void)=>queue.subscribeItem(id,notify),[queue,id]),snapshot=useCallback(()=>queue.getItem(id),[queue,id]);
  const item=useSyncExternalStore(subscribe,snapshot,snapshot);
  if(!item)return null;
  const {state} = item;
  const showProgress = state==='uploading'||state==='paused';
  const progress = item.size>0 ? Math.min(100,item.loaded/item.size*100) : 0;
  const labels:Record<TransferItem['state'],string>={queued:t.queued,resolving:t.resolving,uploading:t.uploading,paused:t.paused,finalizing:t.finalizing,completed:t.completed,error:t.failed,cancelled:t.cancelled};
  const detail = item.error??(showProgress ? `${labels[state]} · ${formatBytes(item.loaded)} / ${formatBytes(item.size)}` : labels[state]);
  const statusIcon = state==='completed' ? CheckmarkCircle03Icon : state==='error' ? AlertCircleIcon : File01Icon;

  return <div className={`fms-transfer-row state-${state}`}>
    <span className="fms-transfer-file"><Icon icon={statusIcon} size={18}/></span>
    <div className="fms-transfer-description">
      <strong title={item.relativePath||item.name}>{item.name}</strong>
      <span title={detail}>{detail}</span>
      {showProgress&&<ProgressBar aria-label={item.name} value={progress} size="sm"><ProgressBar.Track><ProgressBar.Fill/></ProgressBar.Track></ProgressBar>}
    </div>
    <div className="fms-transfer-controls">
    {['queued','resolving','uploading'].includes(item.state)&&<Button isIconOnly size="sm" variant="ghost" aria-label={`${t.pause}: ${item.name}`} onPress={()=>queue.pause(id)}><Icon icon={PauseIcon} size={16}/></Button>}
    {item.state==='paused'&&<Button isIconOnly size="sm" variant="ghost" isDisabled={allPaused} aria-label={`${t.resume}: ${item.name}`} onPress={()=>queue.resume(id)}><Icon icon={PlayIcon} size={16}/></Button>}
    {item.state==='error'&&<Button isIconOnly size="sm" variant="ghost" aria-label={`${t.retry}: ${item.name}`} onPress={()=>queue.retry(id)}><Icon icon={RefreshIcon} size={16}/></Button>}
    {!['completed','cancelled','finalizing'].includes(item.state)&&<Button isIconOnly size="sm" variant="ghost" aria-label={`${t.cancelUpload}: ${item.name}`} onPress={()=>queue.cancel(id)}><Icon icon={Cancel01Icon} size={16}/></Button>}
  </div></div>;
});
