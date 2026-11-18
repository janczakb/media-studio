import {useStudioTimeZone,useStudioLocale} from '../layout/TimePreferences';
import {Button, Spinner} from '@heroui/react';
import {useVirtualizer} from '@tanstack/react-virtual';
import {useEffect, useRef, useState, type ReactNode} from 'react';
import type {CommentPage, FileComment} from '../../contracts';
import {errorText, type Messages} from '../../ui';

const PAGE_SIZE=30;
const RETAINED_PAGES=8;


/** Cursor history is lightweight; only eight pages of comment/profile payloads are retained. */
export function VirtualCommentFeed({load,render,t,onPage,onDenied}:{load:(cursor:string|null,signal:AbortSignal)=>Promise<CommentPage>;render:(comment:FileComment)=>ReactNode;t:Messages;onPage:(page:CommentPage)=>void;onDenied:()=>void}) {
  const zone=useStudioTimeZone(),locale=useStudioLocale(),commentDay=new Intl.DateTimeFormat(locale,{weekday:'short',month:'short',day:'numeric',timeZone:zone}),commentDayKey=new Intl.DateTimeFormat(locale,{year:'numeric',month:'numeric',day:'numeric',timeZone:zone});
  const scroll=useRef<HTMLDivElement|null>(null),loader=useRef(load),pageCallback=useRef(onPage),denied=useRef(onDenied);
  loader.current=load;pageCallback.current=onPage;denied.current=onDenied;
  const request=useRef<AbortController|null>(null),active=useRef(false),cursors=useRef<(string|null)[]>([null]);
  const pageEndDays=useRef<string[]>([]);
  const [pages,setPages]=useState(new Map<number,FileComment[]>()),[length,setLength]=useState(0),[more,setMore]=useState(true),[busy,setBusy]=useState(false),[error,setError]=useState(''),[attempt,retry]=useState(0);
  const virtual=useVirtualizer({count:length+(more?1:0),getScrollElement:()=>scroll.current,estimateSize:()=>76,overscan:4,getItemKey:index=>index});
  const rows=virtual.getVirtualItems(),first=rows[0]?.index??0,last=rows.at(-1)?.index??0;
  useEffect(()=>()=>request.current?.abort(),[]);
  useEffect(()=>{
    if(active.current||error)return;
    const wanted=Array.from({length:Math.floor(last/PAGE_SIZE)-Math.floor(first/PAGE_SIZE)+1},(_,i)=>Math.floor(first/PAGE_SIZE)+i).find(index=>!pages.has(index)&&index<cursors.current.length);
    if(wanted===undefined)return;
    const controller=new AbortController();request.current=controller;active.current=true;setBusy(true);
    void loader.current(cursors.current[wanted]!,controller.signal).then(page=>{
      if(controller.signal.aborted)return;
      if(page.items.length>PAGE_SIZE||(page.nextCursor!==null&&page.items.length!==PAGE_SIZE))throw new Error('Invalid comment page.');
      if(page.nextCursor!==null){if(page.nextCursor===cursors.current[wanted])throw new Error('Repeated comment cursor.');cursors.current[wanted+1]=page.nextCursor;}
      const lastComment=page.items.at(-1);
      if(lastComment)pageEndDays.current[wanted]=lastComment.createdAt;
      setLength(value=>Math.max(value,wanted*PAGE_SIZE+page.items.length));
      if(page.nextCursor===null)setMore(false);
      setPages(previous=>{const next=new Map(previous);next.set(wanted,page.items);while(next.size>RETAINED_PAGES){const victim=[...next.keys()].filter(key=>key!==wanted).sort((a,b)=>Math.abs(b-wanted)-Math.abs(a-wanted))[0];if(victim===undefined)break;next.delete(victim);}return next;});
      pageCallback.current(page);
    }).catch(reason=>{if(controller.signal.aborted)return;if(reason?.status===403||reason?.status===404)denied.current();else setError(errorText(reason));}).finally(()=>{if(!controller.signal.aborted){active.current=false;setBusy(false);}});
  },[first,last,pages,error,attempt]);
  return <div ref={scroll} className="fms-comments-scroll" role="feed" aria-label={t.comments} aria-busy={busy}>
    <div style={{height:virtual.getTotalSize(),position:'relative'}}>
      {rows.map(row=>{
        const pageIndex=Math.floor(row.index/PAGE_SIZE),offset=row.index%PAGE_SIZE;
        const page=pages.get(pageIndex),item=page?.[offset],previous=page?.[offset-1];
        const date=item?new Date(item.createdAt):null;
        const previousDay=previous?commentDayKey.format(new Date(previous.createdAt)):pageEndDays.current[pageIndex-1]?commentDayKey.format(new Date(pageEndDays.current[pageIndex-1]!)):undefined;
        const newDay=date&&(row.index===0||(previousDay!==undefined&&commentDayKey.format(date)!==previousDay));
        return <div key={row.key} data-index={row.index} ref={item?virtual.measureElement:undefined} className="fms-comment-virtual-row" style={{position:'absolute',insetInline:0,top:0,transform:`translateY(${row.start}px)`}}>
          {newDay&&<div className="fms-comment-day"><time dateTime={item?.createdAt}>{commentDay.format(date)}</time></div>}
          {item?render(item):<div className="fms-comment-page-loading"><Spinner size="sm" aria-label={t.loading}/></div>}
        </div>;
      })}
    </div>
    {!busy&&!more&&length===0&&<p className="fms-comment-empty fms-muted">{t.commentsEmpty}</p>}
    {error&&<div className="fms-error" role="alert">{error}<Button size="sm" variant="ghost" onPress={()=>{setError('');retry(n=>n+1);}}>{t.retry}</Button></div>}
  </div>;
}
