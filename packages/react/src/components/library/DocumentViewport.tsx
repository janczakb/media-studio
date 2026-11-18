import {Button,Input,Tooltip} from '@heroui/react';
import {Add01Icon,Remove01Icon} from '@hugeicons/core-free-icons';
import {useCallback,useRef,useState} from 'react';
import type {OfficeViewportControl,OfficeViewportState} from '../../office-preview-contract';
import {Icon,type Messages} from '../../ui';

export function useDocumentViewport(key:string) {
  const activeKey=useRef(key);activeKey.current=key;
  const [session,setSession]=useState<{key:string;state:OfficeViewportState}>();
  const [commands,setCommands]=useState<{key:string;control:OfficeViewportControl}>();
  const sequence=useRef(0);
  const onViewport=useCallback((state:OfficeViewportState)=>{
    if(activeKey.current!==key)return;
    setSession(previous=>previous?.key===key&&previous.state.page===state.page&&previous.state.total===state.total&&previous.state.zoom===state.zoom&&previous.state.fit===state.fit&&previous.state.minZoom===state.minZoom&&previous.state.maxZoom===state.maxZoom?previous:{key,state});
  },[key]);
  const changeViewport=useCallback((command:OfficeViewportControl)=>{
    const request=++sequence.current;
    setCommands(previous=>({key,control:{...(previous?.key===key?previous.control:{}),...(command.page?{page:{...command.page,request}}:{}),...(command.zoom?{zoom:{...command.zoom,request}}:{})}}));
  },[key]);
  return {state:session?.key===key?session.state:{page:0,total:0,zoom:1,fit:true},control:commands?.key===key?commands.control:undefined,onViewport,changeViewport};
}

export function DocumentPageControl({state,onChange,t}: {state:OfficeViewportState;onChange:(command:OfficeViewportControl)=>void;t:Messages}) {
  const [draft,setDraft]=useState<string>();
  const skipBlur=useRef(false);
  const commit=()=>{if(draft!==undefined){const value=Number(draft);if(Number.isInteger(value)&&value>0)onChange({page:{value:Math.min(value,state.total),request:0}});setDraft(undefined);}};
  if(!Number.isInteger(state.page)||!Number.isInteger(state.total)||state.page<1||state.total<state.page)return null;
  return <div className="fms-document-page-control" tabIndex={-1} aria-label={t.documentPages}>
    <span className="fms-document-page-separator" aria-hidden="true">/</span><span className="fms-document-page-label">{t.documentPage}</span>
    <Input className="fms-document-page-input" aria-label={t.documentPage} inputMode="numeric" type="text" value={draft??(state.page?String(state.page):'—')} disabled={!state.total}
      onFocus={()=>{skipBlur.current=false;}} onChange={event=>setDraft(event.target.value.replace(/[^0-9]/g,''))} onBlur={()=>{if(skipBlur.current)skipBlur.current=false;else commit();}}
      onKeyDown={event=>{event.stopPropagation();if(event.key==='Enter'){event.preventDefault();skipBlur.current=true;commit();event.currentTarget.parentElement?.focus({preventScroll:true});}else if(event.key==='Escape'){event.preventDefault();skipBlur.current=true;setDraft(undefined);event.currentTarget.parentElement?.focus({preventScroll:true});}}}/>
    <span className="fms-document-page-of">{t.findOf}</span><span>{state.total||'—'}</span>
  </div>;
}
const zoomSteps=[.25,.33,.5,.67,.75,.9,1,1.1,1.25,1.5,1.75,2,2.5,3];
export function DocumentZoomControls({state,onChange,t,portal,minZoom=.25,maxZoom=3,steps=zoomSteps,multiplicative=false,actualSizeOnly=false}: {state:OfficeViewportState;onChange:(command:OfficeViewportControl)=>void;t:Messages;portal:HTMLDivElement;minZoom?:number;maxZoom?:number;steps?:number[];multiplicative?:boolean;actualSizeOnly?:boolean}) {
  const tip=(label:string,child:React.ReactNode)=><Tooltip delay={300}>{child}<Tooltip.Content UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content></Tooltip>;
  const step=(direction:1|-1)=>{const value=multiplicative?Math.max(minZoom,Math.min(maxZoom,state.zoom*(direction>0?1.25:.75))):direction>0?steps.find(value=>value>state.zoom+.001):[...steps].reverse().find(value=>value<state.zoom-.001);if(value)onChange({zoom:{value,request:0}});};
  if(!state.total||!Number.isFinite(state.zoom)||state.zoom<=0)return null;
  return <div className="fms-document-header-zoom" role="group" aria-label={t.documentZoom}>
    {tip(t.zoomOut,<Button isIconOnly variant="ghost" size="sm" aria-label={t.zoomOut} isDisabled={!state.total||state.zoom<=minZoom} onPress={()=>step(-1)}><Icon icon={Remove01Icon} size={20} strokeWidth={1.5}/></Button>)}
    {tip(actualSizeOnly||state.fit?t.documentActualSize:t.documentFit,<Button className="fms-document-zoom-value" variant="ghost" size="sm" aria-label={actualSizeOnly||state.fit?t.documentActualSize:t.documentFit} isDisabled={!state.total} onPress={()=>onChange({zoom:{value:actualSizeOnly||state.fit?1:'fit',request:0}})}>{Math.round(state.zoom*100)}%</Button>)}
    {tip(t.zoomIn,<Button isIconOnly variant="ghost" size="sm" aria-label={t.zoomIn} isDisabled={!state.total||state.zoom>=maxZoom} onPress={()=>step(1)}><Icon icon={Add01Icon} size={20} strokeWidth={1.5}/></Button>)}
  </div>;
}
