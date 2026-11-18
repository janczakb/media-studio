import {useEffect,useRef,useState} from 'react';
import {Button,Spinner} from '@heroui/react';
import type {Entry} from '../../contracts';
import type {Messages} from '../../ui';
import type {OfficeViewportControl,OfficeViewportState,OfficeFindState,OfficeFindResult} from '../../office-preview-contract';
export interface PDFPreviewSettings {maxBytes?:number;maxPages?:number;timeoutMs?:number}
export function supportsPDFPreview(entry:Entry,settings:false|PDFPreviewSettings|undefined){return settings!==false&&(entry.mimeType==='application/pdf'||/\.pdf$/i.test(entry.name));}
type Reader=Awaited<ReturnType<typeof import('../../pdf-preview').mountPDFReader>>;
let module:Promise<typeof import('../../pdf-preview')>|undefined;
const load=():Promise<typeof import('../../pdf-preview')>=>module??=import(new URL('./pdf-preview.js',import.meta.url).href).catch(error=>{module=undefined;throw error;});
export function PDFPreview({entry,url,t,active=true,settings={},viewport,onViewport,search,onSearchResult,onPrintReady}:{search?:OfficeFindState|undefined;onSearchResult?:(value:OfficeFindResult)=>void;onPrintReady?:(print:(()=>Promise<void>)|undefined)=>void;entry:Entry;url:string;t:Messages;active?:boolean;settings?:PDFPreviewSettings;viewport:OfficeViewportControl|undefined;onViewport:(state:OfficeViewportState)=>void}){
 const bar=useRef<HTMLDivElement>(null);
 const host=useRef<HTMLDivElement>(null),reader=useRef<Reader|undefined>(undefined);
 const callbacks=useRef(onViewport);callbacks.current=onViewport;
 const external=useRef({onSearchResult,onPrintReady,search});external.current={onSearchResult,onPrintReady,search};
 const lastQuery=useRef<string|undefined>(undefined),lastStep=useRef<number|undefined>(undefined);
 const find=()=>{
  const view=reader.current,value=external.current.search;if(!view||!value)return;
  const query=value.open?value.query:'';
  if(query!==lastQuery.current){lastQuery.current=query;lastStep.current=value.step;void view.search(query).catch(()=>{});}
  else if(value.step!==lastStep.current){lastStep.current=value.step;void view.nextSearchResult(value.direction).catch(()=>{});}
 };
 useEffect(()=>{find();},[search?.query,search?.open,search?.step]);
 const [state,setState]=useState<'loading'|'ready'|'failed'>('loading'),[attempt,setAttempt]=useState(0);
 const latest=useRef(viewport);latest.current=viewport;
 const consumed=useRef({page:-1,zoom:-1});
 const apply=(control:OfficeViewportControl|undefined)=>{
  const view=reader.current;if(!view||!control)return;
  if(control.page&&control.page.request!==consumed.current.page){consumed.current.page=control.page.request;void view.setPage(control.page.value-1).catch(()=>{});}
  if(control.zoom&&control.zoom.request!==consumed.current.zoom){consumed.current.zoom=control.zoom.request;void view.setZoom(control.zoom.value==='fit'?'fit-width':control.zoom.value).catch(()=>{});}
 };
 useEffect(()=>{apply(viewport);},[viewport]);
 const maxBytes=settings.maxBytes??50*1024*1024,maxPages=settings.maxPages??2000,timeout=settings.timeoutMs??60000;
 useEffect(()=>{
  const element=host.current;if(!element||!active)return;
  const controller=new AbortController();let view:Reader|undefined;
  lastQuery.current=undefined;lastStep.current=undefined;external.current.onPrintReady?.(undefined);
  consumed.current={page:-1,zoom:-1};setState('loading');callbacks.current({page:0,total:0,zoom:1,fit:true});
  const shadow=element.shadowRoot??element.attachShadow({mode:'open'}),mount=document.createElement('div'),link=document.createElement('link');
  link.rel='stylesheet';link.href=new URL('./pdf-preview.css',import.meta.url).href;
  mount.className='fms-pdf-reader';shadow.replaceChildren(link,mount);
  const progress=(value:{phase:string;loaded:number;total:number|null})=>{
   const track=bar.current;if(!track||controller.signal.aborted)return;
   const done=value.phase==='ready'||value.phase==='render'&&value.loaded===value.total;
   track.dataset.complete=String(done);track.setAttribute('aria-hidden',String(done));
   const determinate=value.phase==='download'&&value.total!==null&&value.total>0;
   track.dataset.indeterminate=String(!done&&!determinate);
   if(determinate)track.setAttribute('aria-valuenow',String(Math.floor(value.loaded/value.total!*100)));else track.removeAttribute('aria-valuenow');
   track.style.setProperty('--fms-document-progress',String(done?1:determinate?Math.min(.98,value.loaded/value.total!):0));
  };
  const css=new Promise<void>((resolve,reject)=>{link.onload=()=>resolve();link.onerror=()=>reject(Error('Stylesheet unavailable'));});
  void Promise.all([load(),css]).then(async([engine])=>{
   if(controller.signal.aborted)return;
   view=await engine.mountPDFReader(mount,{source:url,workerUrl:new URL('./pdfium-worker.js',import.meta.url).href,wasmUrl:new URL('./pdfium.wasm',import.meta.url).href,maxBytes,maxPages,openTimeoutMs:timeout,signal:controller.signal,onProgress:progress,
    onSearchChange:value=>{if(!controller.signal.aborted)external.current.onSearchResult?.({index:value.index,total:value.total});},
    onChange:value=>{if(!controller.signal.aborted)callbacks.current({page:value.pageIndex+1,total:value.pageCount,zoom:value.scale,fit:value.fit});},
    onError:()=>{if(!controller.signal.aborted)setState('failed');},
   });
   if(controller.signal.aborted){view.destroy();return;}
   reader.current=view;setState('ready');apply(latest.current);find();
   external.current.onPrintReady?.(async()=>{await view!.print({title:entry.name,assetBaseUrl:new URL('./',import.meta.url).href});});
  }).catch(()=>{if(!controller.signal.aborted)setState('failed');});
  return()=>{external.current.onPrintReady?.(undefined);controller.abort();view?.destroy();reader.current=undefined;link.onload=link.onerror=null;shadow.replaceChildren();};
 },[url,entry.versionId,active,maxBytes,maxPages,timeout,attempt]);
 return <div className="fms-office-preview fms-pdf-preview" aria-busy={state==='loading'}><div ref={bar} className="fms-document-progress" role="progressbar" aria-label={t.loadingDocument} aria-valuemin={0} aria-valuemax={100}><span/></div><div className="fms-office-host" ref={host}/>{state==='loading'&&<div className="fms-document-status" role="status"><Spinner aria-label={t.loadingDocument}/></div>}{state==='failed'&&<div className="fms-document-status" role="alert"><p>{t.previewFailed}</p><Button size="sm" onPress={()=>setAttempt(value=>value+1)}>{t.retry}</Button></div>}</div>;
}
