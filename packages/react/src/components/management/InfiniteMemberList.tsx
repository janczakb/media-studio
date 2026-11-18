import {TableEmptyState} from '../TableEmptyState';
import {Button, Spinner} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect, useRef, useState, type ReactNode} from 'react';

type Page<T>={items:T[];nextCursor:string|null};
type Props<T>={identity:string;load:(cursor:string|null,signal:AbortSignal)=>Promise<Page<T>>;getKey:(item:T)=>string;render:(item:T)=>ReactNode;empty:string;retry:string};
/** Profile payloads are bounded to eight pages. Evicted pages are refetched on backward scrolling. */
export function InfiniteMemberList<T>(props:Props<T>){
 return <MemberWindow key={props.identity} {...props}/>;
}
function MemberWindow<T>({load,getKey,render,empty,retry}:Props<T>){
 const scroll=useRef<HTMLDivElement>(null);
 const loader=useRef(load);loader.current=load;
 const request=useRef<AbortController|null>(null);
 const active=useRef(false);
 const cursors=useRef<(string|null)[]>([null]);
 const [pages,setPages]=useState(new Map<number,T[]>());
 const [length,setLength]=useState(0),[more,setMore]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const [attempt,setAttempt]=useState(0);
 const virtual=useVirtualizer({count:length+(more?1:0),getScrollElement:()=>scroll.current,estimateSize:()=>80,overscan:4,getItemKey:index=>{const item=pages.get(Math.floor(index/25))?.[index%25];return item?getKey(item):`pending-${index}`;}});
 const rows=virtual.getVirtualItems();
 const first=rows[0]?.index??0,last=rows.at(-1)?.index??0;
 useEffect(()=>()=>request.current?.abort(),[]);
 useEffect(()=>{
  if(active.current||error)return;
  const missing=Array.from({length:Math.floor(last/25)-Math.floor(first/25)+1},(_,i)=>Math.floor(first/25)+i).find(page=>!pages.has(page)&&page<cursors.current.length);
  if(missing===undefined)return;
  const controller=new AbortController();request.current=controller;active.current=true;setBusy(true);
  void loader.current(cursors.current[missing]!,controller.signal).then(page=>{
   if(controller.signal.aborted)return;
   if(page.items.length>25||page.nextCursor&&page.items.length!==25)throw new Error('Invalid member page.');
   if(page.nextCursor!==null)cursors.current[missing+1]=page.nextCursor;
   setLength(previous=>Math.max(previous,missing*25+page.items.length));
   if(page.nextCursor===null)setMore(false);
   setPages(previous=>{const next=new Map(previous);next.set(missing,page.items);while(next.size>8){const victim=[...next.keys()].filter(key=>key!==missing).sort((a,b)=>Math.abs(b-missing)-Math.abs(a-missing))[0];if(victim===undefined)break;next.delete(victim);}return next;});
  }).catch(reason=>{if(!controller.signal.aborted)setError(reason instanceof Error?reason.message:'Error');}).finally(()=>{if(!controller.signal.aborted){active.current=false;setBusy(false);}});
 },[first,last,pages,attempt,error]);
 return <><div className="fms-management-list" ref={scroll} role="list" tabIndex={0} aria-busy={busy}><div style={{height:virtual.getTotalSize(),position:'relative'}}>{rows.map(row=>{const item=pages.get(Math.floor(row.index/25))?.[row.index%25];return <div role="listitem" key={row.key} className="fms-management-row" style={{position:'absolute',insetInline:0,top:0,height:row.size,transform:`translateY(${row.start}px)`}}>{item?render(item):<Spinner size="sm"/>}</div>;})}</div>{!busy&&!more&&length===0&&<TableEmptyState kind="members" label={empty}/>}</div>{error&&<div role="alert">{error}<Button size="sm" variant="tertiary" onPress={()=>{setError('');setAttempt(value=>value+1);}}>{retry}</Button></div>}</>;
}
