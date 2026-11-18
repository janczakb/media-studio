import {Button,Spinner} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect,useLayoutEffect,useRef,type ReactNode} from 'react';
import type {AssistantMessage} from './contracts';

/** End anchoring preserves the visible message through prepend, trimming and live resizing. */
export function VirtualAssistantMessages({messages,render,stream,older,newer,busy,error,load,pl}:{messages:AssistantMessage[];render:(item:AssistantMessage)=>ReactNode;stream:ReactNode;older:boolean;newer:boolean;busy:boolean;error:boolean;load:(direction:'older'|'newer')=>void;pl:boolean}){
  const scroll=useRef<HTMLDivElement>(null),initial=useRef(false),callback=useRef(load);callback.current=load;
  const count=messages.length+(newer||stream?1:0);
  const virtual=useVirtualizer({count,getScrollElement:()=>scroll.current,estimateSize:index=>index>=messages.length?48:150,overscan:3,paddingStart:older?48:0,anchorTo:'end',followOnAppend:newer?false:'auto',scrollEndThreshold:80,getItemKey:index=>index>=messages.length?(newer?'newer':'stream'):messages[index]!.id});
  useEffect(()=>{const keys=new Set<string>([...messages.map(m=>m.id),'newer','stream']);for(const key of virtual.itemSizeCache.keys())if(!keys.has(String(key)))virtual.itemSizeCache.delete(key);},[messages,virtual]);
  const rows=virtual.getVirtualItems(),first=rows[0]?.index??0,last=rows.at(-1)?.index??0;
  useLayoutEffect(()=>{if(!initial.current&&messages.length){initial.current=true;virtual.scrollToEnd({behavior:'auto'});}},[messages.length,virtual]);
  useEffect(()=>{if(busy||error||!initial.current)return;if(older&&first===0)callback.current('older');else if(newer&&last>=count-1)callback.current('newer');},[first,last,older,newer,count,busy,error]);
  return <div className="fms-assistant-messages" ref={scroll} role="log" aria-busy={busy} aria-live="off" aria-label={pl?'Wiadomości':'Messages'}><div style={{height:virtual.getTotalSize(),position:'relative'}}>{older&&<Button style={{position:'absolute',top:0}} className="fms-assistant-load-messages" size="sm" variant="ghost" isDisabled={busy} onPress={()=>load('older')}>{busy?<Spinner size="sm"/>:(pl?'Wczytaj starsze':'Load older')}</Button>}{rows.map(row=>{const message=messages[row.index];const edge=row.index>=messages.length;return <div key={row.key} data-index={row.index} ref={virtual.measureElement} className="fms-assistant-message-virtual-row" style={{position:'absolute',insetInline:0,top:0,transform:`translateY(${row.start}px)`}}>{!edge&&message?render(message):row.key==='stream'?stream:<Button className="fms-assistant-load-messages" size="sm" variant="ghost" isDisabled={busy} onPress={()=>load('newer')}>{busy?<Spinner size="sm"/>:pl?'Wczytaj nowsze':'Load newer'}</Button>}</div>;})}</div></div>;
}
