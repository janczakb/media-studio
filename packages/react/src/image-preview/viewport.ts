export interface ImageViewportState {ready:boolean;zoom:number;fit:boolean;fitZoom:number;x:number;y:number;minZoom:number;maxZoom:number}
export interface ImageViewportCommand {point?:{x:number;y:number};animate?:boolean;center?:boolean}
export interface ImageViewportHandle {
 getState():ImageViewportState;
 zoomTo(value:number|'fit',options?:ImageViewportCommand):void;
 zoomBy(factor:number,options?:ImageViewportCommand):void;
 panBy(x:number,y:number):void;
 subscribe(listener:(state:ImageViewportState)=>void):()=>void;
 destroy():void;
}
export interface ImageViewportOptions {
 minZoom?:number;maxZoom?:number;maxRelativeZoom?:number;fitPadding?:number;animationMs?:number;gestures?:boolean;
 wheelBehavior?:'pan'|'zoom';
}
const cosineEase='linear('+Array.from({length:33},(_,index)=>(.5-Math.cos(index/32*Math.PI)/2).toFixed(6)).join(',')+')';
interface Point{x:number;y:number}
interface NativeGesture extends Event {scale:number;clientX:number;clientY:number}
const clamp=(v:number,min:number,max:number)=>Math.max(min,Math.min(max,v));

/** One image camera. The layout box changes on resize, never on zoom frames. */
export function createImageViewport(plane:HTMLElement,frame:HTMLElement,options:ImageViewportOptions={},interaction?:(active:boolean)=>void):ImageViewportHandle&{setImageSize(width:number,height:number):void;setEnabled(enabled:boolean):void} {
 const box=plane.parentElement!;
 const configuredMinimum=Number.isFinite(options.minZoom)&&options.minZoom!>0?options.minZoom!:undefined;
 let minimum=.05;
 const configuredMaximum=Number.isFinite(options.maxZoom)&&options.maxZoom!>0?options.maxZoom!:undefined;
 const relativeMaximum=Number.isFinite(options.maxRelativeZoom)&&options.maxRelativeZoom!>0?options.maxRelativeZoom!:8;
 const padding=Number.isFinite(options.fitPadding)?clamp(options.fitPadding!,0,.5):.1;
 let maximum=8,rawFit=0;
 const duration=Number.isFinite(options.animationMs)?clamp(options.animationMs!,0,1000):200;
 let natural={width:0,height:0},bounds={width:0,height:0},fitZoom=0,zoom=1,x=0,y=0,fitted=true,enabled=true,disposed=false;
 let layoutStamp='';
 let animation:Animation|undefined,raf=0,notice:ReturnType<typeof setTimeout>|undefined;
 let pendingWheel={x:0,y:0,zoom:0,point:{x:0,y:0}};
 const listeners=new Set<(state:ImageViewportState)=>void>(),pointers=new Map<number,Point>();
 let previous:{center:Point;distance:number}|undefined,nativeScale=1,nativeActive=false;
 const ready=()=>!disposed&&fitZoom>0;
 const state=():ImageViewportState=>({ready:ready(),zoom,fit:fitted,fitZoom,x,y,minZoom:Math.min(minimum,fitZoom||minimum),maxZoom:maximum});
 const notify=()=>{if(!disposed)for(const listener of listeners)listener(state());};
 const reduced=()=>window.matchMedia('(prefers-reduced-motion: reduce)').matches;
 const matrix=()=>`translate3d(${x}px,${y}px,0) scale(${fitZoom?zoom/fitZoom:1})`;
 const constrain=()=>{const maxX=Math.max(0,(natural.width*zoom-bounds.width)/2),maxY=Math.max(0,(natural.height*zoom-bounds.height)/2);x=clamp(x,-maxX,maxX);y=clamp(y,-maxY,maxY);};
 const stop=()=>{if(animation){const current=new DOMMatrix(getComputedStyle(plane).transform);zoom=current.a*fitZoom;x=current.m41;y=current.m42;animation.cancel();animation=undefined;}};
 const paint=(from?:string)=>{
  const target=matrix();plane.style.transform=target;
  if(from&&duration&&!reduced()){
   const next=plane.animate([{transform:from},{transform:target}],{duration,easing:CSS.supports('animation-timing-function',cosineEase)?cosineEase:'cubic-bezier(.37,0,.63,1)'});animation=next;
   void next.finished.then(()=>{if(animation===next){animation=undefined;next.cancel();}}).catch(()=>{});
  }
 };
 const local=(point:Point):Point=>{const r=frame.getBoundingClientRect();return {x:point.x-r.left-r.width/2,y:point.y-r.top-r.height/2};};
 const applyZoom=(value:number|'fit',command:ImageViewportCommand={},publish=true)=>{
  if(!ready()||disposed)return;
  stop();const from=matrix(),old=zoom;
  zoom=value==='fit'?fitZoom:clamp(Number.isFinite(value)?value:zoom,Math.min(minimum,fitZoom),maximum);fitted=value==='fit'||Math.abs(zoom-fitZoom)<.000001;
  const p=command.point?local(command.point):{x:0,y:0};
  x=p.x-(p.x-x)*zoom/old;y=p.y-(p.y-y)*zoom/old;
  if(fitted||command.center){x=0;y=0;}
  constrain();paint(command.animate===false?undefined:from);if(publish)notify();
 };
 const panBy=(dx:number,dy:number,publish=true)=>{if(!ready()||disposed||!Number.isFinite(dx)||!Number.isFinite(dy))return;stop();x+=dx;y+=dy;constrain();paint();if(publish)notify();};
 const resize=()=>{
  if(disposed||!natural.width||!natural.height)return;
  const css=getComputedStyle(frame);
  const next={width:Math.max(1,frame.clientWidth-parseFloat(css.paddingLeft)-parseFloat(css.paddingRight)),height:Math.max(1,frame.clientHeight-parseFloat(css.paddingTop)-parseFloat(css.paddingBottom))};
  const dpr=window.devicePixelRatio||1;
  const stamp=[natural.width,natural.height,next.width,next.height,dpr].join(':');if(stamp===layoutStamp)return;layoutStamp=stamp;stop();bounds=next;
  rawFit=Math.min(bounds.width/natural.width,bounds.height/natural.height);
  const textureMaximum=rawFit*Math.min(16384/(bounds.width*dpr),16384/(bounds.height*dpr));
  minimum=Math.min(textureMaximum,configuredMinimum??Math.min(rawFit*(1-padding),1));
  maximum=Math.max(minimum,Math.min(configuredMaximum??rawFit*relativeMaximum,textureMaximum));
  fitZoom=clamp(rawFit*(1-padding),minimum,maximum);
  box.style.width=natural.width*fitZoom+'px';box.style.height=natural.height*fitZoom+'px';
  if(fitted){zoom=fitZoom;x=0;y=0;}
  constrain();paint();notify();
 };
 const observer=new ResizeObserver(resize);observer.observe(frame);window.addEventListener('resize',resize);
 const laterNotice=()=>{if(notice)clearTimeout(notice);notice=setTimeout(()=>{notice=undefined;notify();},90);};
 const wheel=(event:WheelEvent)=>{
  if(!enabled||!ready()||options.gestures===false)return;
  if(nativeActive){event.preventDefault();return;}
  const z=event.ctrlKey||event.metaKey||options.wheelBehavior!=='pan';
  const canPan=natural.width*zoom>bounds.width+.5||natural.height*zoom>bounds.height+.5;
  if(!z&&!canPan)return;
  event.preventDefault();event.stopPropagation();
  const unit=event.deltaMode===1?16:event.deltaMode===2?bounds.height:1;
  pendingWheel.point={x:event.clientX,y:event.clientY};
  if(z)pendingWheel.zoom+=clamp(-event.deltaY*unit*.01,-2,2)*rawFit;
  else {pendingWheel.x-=event.deltaX*unit;pendingWheel.y-=event.deltaY*unit;}
  if(!raf)raf=requestAnimationFrame(()=>{raf=0;const pending=pendingWheel;pendingWheel={x:0,y:0,zoom:0,point:pending.point};if(disposed)return;if(pending.zoom){stop();applyZoom(zoom+pending.zoom,{point:pending.point,animate:false},false);}else panBy(pending.x,pending.y,false);laterNotice();});
 };
 const pair=()=>{const values=[...pointers.values()],a=values[0]!,b=values[1]!;return {center:{x:(a.x+b.x)/2,y:(a.y+b.y)/2},distance:Math.max(1,Math.hypot(a.x-b.x,a.y-b.y))};};
 const down=(event:PointerEvent)=>{
  if(!enabled||options.gestures===false||event.button>0||!ready())return;
  pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});
  if(pointers.size>=2||natural.width*zoom>bounds.width+.5||natural.height*zoom>bounds.height+.5){
   stop();event.stopPropagation();frame.setPointerCapture(event.pointerId);interaction?.(true);
   if(pointers.size>=2){for(const id of pointers.keys())try{frame.setPointerCapture(id);}catch{}previous=pair();}
  }
 };
 const move=(event:PointerEvent)=>{
  const old=pointers.get(event.pointerId);if(!old||!enabled)return;
  pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});
  if(pointers.size>=2){event.preventDefault();event.stopPropagation();const next=pair();if(previous){applyZoom(zoom*next.distance/previous.distance,{point:previous.center,animate:false},false);panBy(next.center.x-previous.center.x,next.center.y-previous.center.y,false);}previous=next;}
  else if(frame.hasPointerCapture(event.pointerId)){event.preventDefault();event.stopPropagation();panBy(event.clientX-old.x,event.clientY-old.y,false);}
 };
 const up=(event:PointerEvent)=>{const captured=frame.hasPointerCapture(event.pointerId);pointers.delete(event.pointerId);if(captured){event.stopPropagation();frame.releasePointerCapture(event.pointerId);}if(pointers.size<2)previous=undefined;if(!pointers.size){interaction?.(false);notify();}};
 const gestureStart=(raw:Event)=>{if(!enabled||options.gestures===false||!ready()||pointers.size>=2)return;const event=raw as NativeGesture;event.preventDefault();event.stopPropagation();stop();nativeScale=event.scale||1;nativeActive=true;interaction?.(true);};
 const gestureChange=(raw:Event)=>{if(!nativeActive)return;const event=raw as NativeGesture;event.preventDefault();event.stopPropagation();applyZoom(zoom*event.scale/nativeScale,{point:{x:event.clientX,y:event.clientY},animate:false},false);nativeScale=event.scale;};
 const gestureEnd=(event:Event)=>{if(nativeActive){event.preventDefault();nativeActive=false;interaction?.(false);notify();}};
 const doubleClick=(event:MouseEvent)=>{if(!enabled||!ready()||options.gestures===false)return;event.preventDefault();event.stopPropagation();applyZoom(zoom*Math.exp(.7),{point:{x:event.clientX,y:event.clientY}});};
 const handlers:[string,EventListener][]=[['wheel',wheel as EventListener],['pointerdown',down as EventListener],['pointermove',move as EventListener],['pointerup',up as EventListener],['pointercancel',up as EventListener],['gesturestart',gestureStart],['gesturechange',gestureChange],['gestureend',gestureEnd],['dblclick',doubleClick as EventListener]];
 for(const [name,handler]of handlers)frame.addEventListener(name,handler,{passive:false,capture:true});
 return {
  getState:state,zoomTo:applyZoom,zoomBy:(factor,command)=>{if(!Number.isFinite(factor)||factor<=0)return;stop();applyZoom(zoom*factor,command);},panBy,
  setImageSize(width,height){if(Number.isFinite(width)&&Number.isFinite(height)&&width>0&&height>0){natural={width,height};resize();}},
  setEnabled(value){if(disposed)return;enabled=value;if(!value){if(raf)cancelAnimationFrame(raf);raf=0;pendingWheel={x:0,y:0,zoom:0,point:{x:0,y:0}};if(notice)clearTimeout(notice);notice=undefined;stop();pointers.clear();previous=undefined;nativeActive=false;interaction?.(false);}},
  subscribe(listener){if(disposed)return()=>{};listeners.add(listener);if(ready())listener(state());return()=>{listeners.delete(listener);};},
  destroy(){if(disposed)return;disposed=true;observer.disconnect();window.removeEventListener('resize',resize);animation?.cancel();if(raf)cancelAnimationFrame(raf);if(notice)clearTimeout(notice);for(const [name,handler]of handlers)frame.removeEventListener(name,handler,true);pointers.clear();listeners.clear();interaction?.(false);}
 };
}
