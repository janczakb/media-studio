import {TableEmptyState,type TableEmptyKind} from '../TableEmptyState';
import {useDateText} from '../layout/TimePreferences';
import {Button, Pagination, Label, ListBox, Modal, Select, Spinner, Tabs} from '@heroui/react';
import {ArrowLeft01Icon, ArrowRight01Icon, RefreshIcon} from '@hugeicons/core-free-icons';
import {useEffect, useId, useRef, useState, type ReactNode} from 'react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {Icon, errorText} from '../../ui';
import type {CursorPage} from '../../management-contracts';
import type en from './locales/en';
import {useStudioNamespace} from '../../workspace-i18n';
export type ManagementLocale = string;
export type ManagementMessages = Record<keyof typeof en, string>;
export const useManagementMessages = (): ManagementMessages => useStudioNamespace('management');
export const dateText = (value: string | null, locale: ManagementLocale) => value && Number.isFinite(Date.parse(value)) ? new Intl.DateTimeFormat(locale, {dateStyle:'medium', timeStyle:'short'}).format(new Date(value)) : '—';

export function useCursorPage<T, P extends CursorPage<T>>(loader: ((cursor: string | null, signal: AbortSignal) => Promise<P>) | undefined, identity: string) {
  const [position,setPosition] = useState<{identity:string;cursor:string|null;page:number}>({identity,cursor:null,page:1});
  if(position.identity!==identity)setPosition({identity,cursor:null,page:1});
  const cursor = position.identity===identity ? position.cursor : null;
  const [attempt,setAttempt] = useState(0);
  const [result,setResult] = useState<{identity:string;cursor:string|null;attempt:number;data:P}|null>(null);
  const [failure,setFailure] = useState<{identity:string;cursor:string|null;attempt:number;message:string}|null>(null);
  const cache=useRef(new Map<string,P>());
  const cacheKey=JSON.stringify([identity,cursor]);
  const load = useRef(loader);
  load.current = loader;
  const available = Boolean(loader);
  useEffect(()=>{
    const controller=new AbortController();
    if(!load.current)return ()=>controller.abort();
    const version = {identity,cursor,attempt};
    void load.current(cursor,controller.signal).then(data=>{
      if(!controller.signal.aborted){cache.current.delete(cacheKey);cache.current.set(cacheKey,data);while(cache.current.size>8)cache.current.delete(cache.current.keys().next().value!);setFailure(null);setResult({...version,data});}
    }).catch(reason=>{
      if(!controller.signal.aborted)setFailure({...version,message:errorText(reason)});
    });
    return ()=>controller.abort();
  },[identity,cursor,attempt,available]);
  const matches = (value:{identity:string;cursor:string|null;attempt:number}|null) => value?.identity===identity&&value.cursor===cursor&&value.attempt===attempt;
  const error=matches(failure)?failure!.message:'';
  const data=result?.identity===identity&&result.cursor===cursor?result.data:cache.current.get(cacheKey)??null;
  return {data,error,pending:available&&!matches(result)&&!error,available,cursor,pageNumber:position.identity===identity?position.page:1,
    setCursor:(next:string)=>setPosition(p=>({identity,cursor:next,page:next===data?.previousCursor?Math.max(1,p.page-1):p.page+1})),refresh:()=>setAttempt(value=>value+1)};
}

export function ManagementTabs({label,value,options,onChange}:{label:string;value:string;options:readonly (readonly [string,string])[];onChange:(value:string)=>void}) {
  return <Tabs variant="secondary" selectedKey={value} onSelectionChange={key=>{if(options.some(([id])=>id===key))onChange(String(key));}}>
    <Tabs.ListContainer><Tabs.List aria-label={label}>{options.map(([id,text])=><Tabs.Tab key={id} id={id}>{text}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer>
  </Tabs>;
}
export function ManagementSelect({label,value,options,onChange,portal}:{label:string;value:string;options:readonly (readonly [string,string])[];onChange:(value:string)=>void;portal:HTMLElement}) {
  return <Select className="fms-management-select" aria-label={label} variant="secondary" value={value} onChange={key=>{if(options.some(([id])=>id===key))onChange(String(key));}}>
    <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
    <Select.Popover UNSTABLE_portalContainer={portal}><ListBox>{options.map(([id,text])=><ListBox.Item id={id} key={id} textValue={text}><Label>{text}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover>
  </Select>;
}
export function PageFrame({title,hint,actions,children}:{title:string;hint:string;actions?:ReactNode;children:ReactNode}) {
  return <section className="fms-management"><header className="fms-management-heading"><div><h1>{title}</h1><p>{hint}</p></div><div className="fms-management-actions">{actions}</div></header>{children}</section>;
}
export function ListState({pending,error,empty,available,retry,t,kind='files'}:{kind?:TableEmptyKind;pending:boolean;error:string;empty:string;available:boolean;retry:()=>void;t:ManagementMessages}) {
  return <div className="fms-management-state" role={error?'alert':'status'}>{pending?<><Spinner size="sm"/><span>{t.loading}</span></>:error?<><span>{error}</span><Button size="sm" variant="tertiary" onPress={retry}>{t.retry}</Button></>:<TableEmptyState kind={kind} label={available?empty:t.unavailable}/>}</div>;
}
export function CursorFooter({previous,next,busy,onPage,refresh,t,pageNumber=1}:{previous?:string|null;next:string|null;busy:boolean;onPage:(cursor:string)=>void;refresh:()=>void;t:ManagementMessages;pageNumber?:number}) {
  return <footer className="fms-management-footer"><Button variant="ghost" size="sm" isIconOnly aria-label={t.refresh} isDisabled={busy} onPress={refresh}><Icon icon={RefreshIcon} size={18}/></Button><span/><Pagination aria-label={t.previous+' / '+t.next}><Pagination.Content><Pagination.Item><Pagination.Previous isDisabled={!previous||busy} onPress={()=>{if(previous)onPage(previous);}}><Pagination.PreviousIcon/><span>{t.previous}</span></Pagination.Previous></Pagination.Item><Pagination.Item><Pagination.Link isActive>{pageNumber}</Pagination.Link></Pagination.Item><Pagination.Item><Pagination.Next isDisabled={!next||busy} onPress={()=>{if(next)onPage(next);}}><span>{t.next}</span><Pagination.NextIcon/></Pagination.Next></Pagination.Item></Pagination.Content></Pagination></footer>;
}
export function VirtualRows<T>({items,getKey,render,identity,onVisible,rowHeight=80}:{items:T[];getKey:(item:T)=>string;render:(item:T)=>ReactNode;identity:string;onVisible?:(items:T[])=>void;rowHeight?:number}) {
  const element=useRef<HTMLDivElement>(null);
  const rows=useVirtualizer({count:items.length,getScrollElement:()=>element.current,estimateSize:()=>rowHeight,overscan:4,getItemKey:index=>getKey(items[index]!)});
  const callback=useRef(onVisible);callback.current=onVisible;const first=rows.range?.startIndex??0,last=rows.range?.endIndex??0;
  useEffect(()=>{const timer=setTimeout(()=>callback.current?.(items.slice(first,last+1)),300);return()=>clearTimeout(timer);},[first,last,items]);
  useEffect(()=>{if(element.current)element.current.scrollTop=0;},[identity]);
  return <div className="fms-management-list" ref={element} role="list" tabIndex={0}><div style={{height:rows.getTotalSize(),position:'relative'}}>{rows.getVirtualItems().map(row=><div role="listitem" key={row.key} className="fms-management-row" style={{position:'absolute',insetInline:0,top:0,transform:`translateY(${row.start}px)`,height:row.size}}>{render(items[row.index]!)}</div>)}</div></div>;
}
export function ConfirmDialog({portal,title,hint,confirm,busy,error,onConfirm,onClose,t,danger=false}:{portal:HTMLElement;title:string;hint:string;confirm:string;busy:boolean;error:string;onConfirm:()=>void;onClose:()=>void;t:ManagementMessages;danger?:boolean}) {
  const titleId=useId();
  return <Modal><Modal.Backdrop isOpen isDismissable={!busy} isKeyboardDismissDisabled={busy} onOpenChange={open=>{if(!open&&!busy)onClose();}} UNSTABLE_portalContainer={portal}>
    <Modal.Container size="sm" placement="center"><Modal.Dialog aria-labelledby={titleId} className="fms-management-dialog"><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading id={titleId}>{title}</Modal.Heading></Modal.Header><Modal.Body><p>{hint}</p>{error&&<p role="alert" className="fms-error">{error}</p>}</Modal.Body><Modal.Footer><Button variant="tertiary" isDisabled={busy} onPress={onClose}>{t.cancel}</Button><Button variant={danger?'danger':'primary'} isDisabled={busy} onPress={onConfirm}>{busy&&<Spinner size="sm"/>}{confirm}</Button></Modal.Footer></Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}

export function DateText({value,locale}:{value:string|null;locale:string}){const format=useDateText(locale);return <>{format(value)}</>;}
