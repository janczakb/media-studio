import {Button,Spinner} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect,useRef,useState,type ReactNode} from 'react';
import type {AssistantConversation} from './contracts';
import {errorText} from '../ui';

/** Six payload pages retained; lightweight cursor checkpoints allow revisiting evicted pages. */
export function VirtualAssistantHistory({load,render,pl}:{load:(cursor:string|null,signal:AbortSignal)=>Promise<{items:AssistantConversation[];nextCursor:string|null}>;render:(item:AssistantConversation)=>ReactNode;pl:boolean}){
  const scroll=useRef<HTMLDivElement>(null),loader=useRef(load),request=useRef<AbortController|null>(null),locked=useRef(false),cursors=useRef<(string|null)[]>([null]);loader.current=load;
  const [pages,setPages]=useState(new Map<number,AssistantConversation[]>()),[length,setLength]=useState(0),[more,setMore]=useState(true),[error,setError]=useState('');
  const virtual=useVirtualizer({count:length+(more?1:0),getScrollElement:()=>scroll.current,estimateSize:()=>40,overscan:4,getItemKey:index=>index});
  const rows=virtual.getVirtualItems(),first=rows[0]?.index??0,last=rows.at(-1)?.index??0;
  useEffect(()=>()=>request.current?.abort(),[]);
  useEffect(()=>{for(const key of virtual.itemSizeCache.keys())if(typeof key==='number'&&!pages.has(Math.floor(key/30)))virtual.itemSizeCache.delete(key);},[pages,virtual]);
  useEffect(()=>{if(locked.current||error)return;let wanted:number|undefined;for(let i=Math.floor(first/30);i<=Math.floor(last/30);i++){if(!pages.has(i)&&i<cursors.current.length){wanted=i;break;}}if(wanted===undefined)return;
    const page=wanted,controller=new AbortController();request.current=controller;locked.current=true;
    void loader.current(cursors.current[page]??null,controller.signal).then(result=>{if(controller.signal.aborted)return;if(result.items.length>30||result.nextCursor===cursors.current[page]&&result.nextCursor!==null)throw new Error('Invalid history page.');
      if(result.nextCursor)cursors.current[page+1]=result.nextCursor;
      setLength(n=>Math.max(n,page*30+result.items.length));if(!result.nextCursor)setMore(false);
      setPages(previous=>{const next=new Map(previous);next.set(page,result.items);while(next.size>6){const key=[...next.keys()].filter(k=>k!==page).sort((a,b)=>Math.abs(b-page)-Math.abs(a-page))[0];if(key===undefined)break;next.delete(key);}return next;});
    }).catch(e=>{if(!controller.signal.aborted)setError(errorText(e));}).finally(()=>{locked.current=false;});
  },[first,last,pages,error]);
  return <div className="fms-assistant-history-scroll" ref={scroll} aria-label={pl?'Lista rozmów':'Conversations'}><div style={{height:virtual.getTotalSize(),position:'relative'}}>{rows.map(row=>{const item=pages.get(Math.floor(row.index/30))?.[row.index%30];return <div key={row.key} data-index={row.index} ref={virtual.measureElement} className="fms-assistant-history-virtual-row" style={{position:'absolute',insetInline:0,top:0,transform:`translateY(${row.start}px)`}}>{item?render(item):<div className="fms-assistant-page-loading"><Spinner size="sm"/></div>}</div>;})}</div>{!more&&!length&&<p>{pl?'Brak rozmów.':'No conversations found.'}</p>}{error&&<p role="alert">{error}<Button size="sm" onPress={()=>setError('')}>{pl?'Ponów':'Retry'}</Button></p>}</div>;
}
