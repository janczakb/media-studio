import {Button, Spinner, Tabs} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect, useLayoutEffect, useRef, useState} from 'react';
import type {AiLabel, StudioTransport} from '../../contracts';
import {errorText} from '../../ui';
import type en from './locales/en';

export function AiLabelsPanel({workspace,transport,t,onOpen}:{workspace:string;transport:NonNullable<StudioTransport['aiLabels']>;t:Record<keyof typeof en,string>;onOpen:(id:string)=>void}) {
  const [filter,setFilter]=useState('pending');
  const [items,setItems]=useState<AiLabel[]>([]);
  const [cursor,setCursor]=useState<string|null>(null);
  const [pending,setPending]=useState(true);
  const [error,setError]=useState('');
  const [canReview,setCanReview]=useState(false);
  const [attempt,setAttempt]=useState(0);
  const scroll=useRef<HTMLDivElement>(null);
  const controllers=useRef(new Set<AbortController>());
  const generation=useRef(0);
  const busy=useRef(false);
  const next=useRef<string|null>(null);
  const removed=useRef(0);
  const [reviewing,setReviewing]=useState<string|null>(null);
  const fetchPage=useRef<(append:boolean)=>void>(()=>{});
  fetchPage.current=append=>{
    if(busy.current||append&&!next.current)return;
    busy.current=true;setPending(true);setError('');
    const controller=new AbortController();controllers.current.add(controller);
    const epoch=generation.current;
    void transport.list(workspace,filter,append?next.current:null,controller.signal).then(page=>{
      if(controller.signal.aborted||epoch!==generation.current)return;
      setCanReview(page.canReview);next.current=page.hasMore?page.nextCursor:null;setCursor(next.current);
      setItems(previous=>{
        const merged=Array.from(new Map([...(append?previous:[]),...page.items.slice(0,60)].map(item=>[item.id,item])).values());
        removed.current=Math.max(0,merged.length-360);
        return merged.slice(-360);
      });
    }).catch(reason=>{if(!controller.signal.aborted&&epoch===generation.current)setError(errorText(reason));})
      .finally(()=>{controllers.current.delete(controller);if(epoch===generation.current&&!controller.signal.aborted){busy.current=false;setPending(false);}});
  };
  useEffect(()=>{
    generation.current++;busy.current=false;next.current=null;setItems([]);setCursor(null);setReviewing(null);
    if(scroll.current)scroll.current.scrollTop=0;
    fetchPage.current(false);
    return ()=>{generation.current++;for(const controller of controllers.current)controller.abort();controllers.current.clear();};
  },[workspace,transport,filter,attempt]);
  const virtual=useVirtualizer({count:items.length,getScrollElement:()=>scroll.current,estimateSize:()=>88,overscan:5,getItemKey:index=>items[index]?.id??index});
  useLayoutEffect(()=>{if(removed.current&&scroll.current){scroll.current.scrollTop=Math.max(0,scroll.current.scrollTop-removed.current*88);removed.current=0;}},[items]);
  const rows=virtual.getVirtualItems();
  const last=rows.at(-1)?.index??-1;
  useEffect(()=>{if(cursor&&last>=items.length-8&&!pending&&!error)fetchPage.current(true);},[cursor,last,items.length,pending,error]);
  const review=(item:AiLabel,state:AiLabel['state'])=>{
    if(reviewing)return;
    const controller=new AbortController();controllers.current.add(controller);setReviewing(item.id);setError('');
    void transport.review(workspace,item.id,state,item.revision,controller.signal).then(result=>{
      if(!controller.signal.aborted)setItems(current=>current.flatMap(row=>row.id!==item.id?[row]:filter==='all'||filter===result.state?[{...row,...result}]:[]));
    }).catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{controllers.current.delete(controller);if(!controller.signal.aborted)setReviewing(null);});
  };
  return <div>
    <h2>{t.labels}</h2><p className="fms-ai-panel-hint">{t.labelsHint}</p>
    <Tabs variant="secondary" selectedKey={filter} onSelectionChange={key=>setFilter(String(key))}><Tabs.ListContainer><Tabs.List aria-label={t.labelStatus}>{(['pending','accepted','rejected','all'] as const).map(id=><Tabs.Tab key={id} id={id}>{t[id]}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer></Tabs>
    {error&&<p role="alert">{error}<Button size="sm" variant="ghost" onPress={()=>setAttempt(n=>n+1)}>{t.retry}</Button></p>}
    <div className="fms-ai-label-scroll" ref={scroll} role="region" aria-label={t.labels} tabIndex={0}>
      <div className="fms-ai-label-window" style={{height:virtual.getTotalSize()}}>{rows.map(row=>{
        const item=items[row.index]!;
        return <div className="fms-ai-label-row" key={item.id} style={{transform:`translateY(${row.start}px)`}}><div><strong>{item.name}</strong><Button size="sm" variant="ghost" onPress={()=>onOpen(item.entryId)}>{item.fileName}</Button><small>{item.provider}{item.score===null?'':` · ${Math.round(item.score*100)}%`}</small></div>{canReview&&<div className="fms-ai-label-actions">{item.state!=='accepted'&&<Button size="sm" variant="secondary" isDisabled={reviewing!==null} onPress={()=>review(item,'accepted')}>{t.accept}</Button>}{item.state!=='rejected'&&<Button size="sm" variant="ghost" isDisabled={reviewing!==null} onPress={()=>review(item,'rejected')}>{t.reject}</Button>}</div>}</div>;
      })}</div>
      {!pending&&!items.length&&!error&&<p>{t.noLabels}</p>}
      {pending&&<Spinner size="sm"/>}
    </div>
  </div>;
}
