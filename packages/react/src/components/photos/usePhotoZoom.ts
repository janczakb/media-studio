import {useCallback,useEffect,useRef,type RefObject} from 'react';
import {photoWheelDelta,type PhotoZoomAnchor} from './photo-zoom';

type PinchEvent=Event&{scale:number;clientX?:number;clientY?:number};
type Owner='wheel'|'touch'|'safari';
export type PhotoZoomOptions={
  viewportRef:RefObject<HTMLElement|null>;
  onBegin:(anchor:PhotoZoomAnchor)=>void;
  onPinch:(logDelta:number,anchor:PhotoZoomAnchor)=>void;
  onEnd:()=>void;
  onSelect:(value:number)=>void;
  enabled?:boolean;
};

/** A single gesture stream, with frame-batched logarithmic input. */
export function usePhotoZoom({viewportRef,onBegin,onPinch,onEnd,onSelect,enabled=true}:PhotoZoomOptions){
  const callbacks=useRef({onBegin,onPinch,onEnd,onSelect});
  callbacks.current={onBegin,onPinch,onEnd,onSelect};
  const setZoom=useCallback((value:number)=>callbacks.current.onSelect(value),[]);
  useEffect(()=>{
    const viewport=viewportRef.current;
    if(!viewport||!enabled)return;
    let owner:Owner|null=null,bounds:DOMRect|null=null,point:PhotoZoomAnchor={x:0,y:0};
    let accumulated=0,frame:number|null=null,timer:ReturnType<typeof setTimeout>|undefined;
    let previousScale=1,touchPair:[number,number]|null=null,touchDistance=0;
    const editable=(event:Event)=>event.composedPath().some(node=>node instanceof HTMLElement&&node.matches('input,textarea,select,[contenteditable="true"],[role="slider"],[data-fms-no-zoom]'));
    const locate=(x?:number,y?:number)=>{
      const box=bounds!;
      point={x:Math.max(0,Math.min(box.width,(x??box.left+box.width/2)-box.left)),y:Math.max(0,Math.min(box.height,(y??box.top+box.height/2)-box.top))};
    };
    const begin=(next:Owner,x?:number,y?:number)=>{
      if(owner&&owner!==next){
        // Safari may emit Touch and Gesture for the same fingers. Gesture owns
        // that sequence; Touch end must never settle it a second time.
        if(next!=='safari')return false;
        owner=next;touchPair=null;touchDistance=0;clearTimeout(timer);
        return true;
      }
      if(!owner){owner=next;bounds=viewport.getBoundingClientRect();locate(x,y);callbacks.current.onBegin(point);}
      else locate(x,y);
      return true;
    };
    const flush=()=>{
      frame=null;
      const change=accumulated;accumulated=0;
      if(owner&&change)callbacks.current.onPinch(change,point);
    };
    const enqueue=(delta:number)=>{
      if(!Number.isFinite(delta))return;
      accumulated+=delta;
      if(frame===null)frame=requestAnimationFrame(flush);
    };
    const finish=()=>{
      clearTimeout(timer);
      if(!owner)return;
      if(frame!==null){cancelAnimationFrame(frame);frame=null;}
      flush();owner=null;bounds=null;touchPair=null;touchDistance=0;
      callbacks.current.onEnd();
    };
    const wheel=(event:WheelEvent)=>{
      if(!event.ctrlKey||editable(event))return;
      event.preventDefault();
      if(!begin('wheel',event.clientX,event.clientY))return;
      enqueue(photoWheelDelta(event.deltaY,event.deltaMode));
      clearTimeout(timer);timer=setTimeout(finish,160);
    };
    const gesture=(event:Event)=>{
      if(editable(event))return;
      const input=event as PinchEvent;
      event.preventDefault();
      if(event.type==='gesturestart'){previousScale=1;begin('safari',input.clientX,input.clientY);return;}
      if(owner!=='safari')return;
      if(event.type==='gestureend'){finish();return;}
      if(Number.isFinite(input.scale)&&input.scale>0){locate(input.clientX,input.clientY);enqueue(Math.log(input.scale/previousScale));previousScale=input.scale;}
    };
    const startTouch=(event:TouchEvent)=>{
      if(owner==='safari'||event.touches.length!==2||editable(event))return;
      const [a,b]=Array.from(event.touches);if(!a||!b)return;
      if(!begin('touch',(a.clientX+b.clientX)/2,(a.clientY+b.clientY)/2))return;
      touchPair=[a.identifier,b.identifier];touchDistance=Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);
    };
    const moveTouch=(event:TouchEvent)=>{
      if(owner!=='touch'||!touchPair||event.touches.length!==2)return;
      const a=Array.from(event.touches).find(t=>t.identifier===touchPair![0]),b=Array.from(event.touches).find(t=>t.identifier===touchPair![1]);
      if(!a||!b)return;
      event.preventDefault();
      const distance=Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);
      if(distance>0&&touchDistance>0){locate((a.clientX+b.clientX)/2,(a.clientY+b.clientY)/2);enqueue(Math.log(distance/touchDistance));touchDistance=distance;}
    };
    const endTouch=(event:TouchEvent)=>{if(owner==='touch'&&(event.type==='touchcancel'||event.touches.length<2))finish();};
    viewport.addEventListener('wheel',wheel,{passive:false});
    for(const type of ['gesturestart','gesturechange','gestureend'])viewport.addEventListener(type,gesture,{passive:false});
    viewport.addEventListener('touchstart',startTouch,{passive:true});
    viewport.addEventListener('touchmove',moveTouch,{passive:false});
    viewport.addEventListener('touchend',endTouch);viewport.addEventListener('touchcancel',endTouch);
    return()=>{
      clearTimeout(timer);if(frame!==null)cancelAnimationFrame(frame);
      viewport.removeEventListener('wheel',wheel);
      for(const type of ['gesturestart','gesturechange','gestureend'])viewport.removeEventListener(type,gesture);
      viewport.removeEventListener('touchstart',startTouch);viewport.removeEventListener('touchmove',moveTouch);
      viewport.removeEventListener('touchend',endTouch);viewport.removeEventListener('touchcancel',endTouch);
    };
  },[viewportRef,enabled]);
  return {setZoom};
}
