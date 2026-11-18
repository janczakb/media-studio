import {ext,send} from '../core/webextension';
import {installContextCapture} from './context-capture';
import {collect} from './collector';
import {installDrag} from './drag-controller';
import type {Preferences} from '../core/types';

const state=globalThis as unknown as {flexCaptureDispose?:()=>void};
if(!state.flexCaptureDispose){
 const owner=new AbortController();let selection:AbortController|null=null;let auto=false;let restoreScroll:{x:number;y:number;hidden:[HTMLElement,string][];behavior:string}|null=null;
 let root:ShadowRoot|null=null;let host:HTMLElement|null=null;
 function surface(){if(root)return root;host=document.createElement('div');host.dataset.flexCapture='true';host.style.cssText='position:fixed;inset:0;z-index:2147483647;pointer-events:none';root=host.attachShadow({mode:'closed'});const css=document.createElement('link');css.rel='stylesheet';css.href=ext.runtime.getURL('content.css');root.append(css);document.documentElement.append(host);return root;}
 function cleanup(){selection?.abort();selection=null;root?.querySelector('.fc-selection')?.remove();root?.querySelector('.fc-box')?.remove();}
 function restore(){if(!restoreScroll)return;for(const [el,visibility]of restoreScroll.hidden)el.style.visibility=visibility;document.documentElement.style.scrollBehavior=restoreScroll.behavior;window.scrollTo(restoreScroll.x,restoreScroll.y);restoreScroll=null;}
 function select(kind:'area'|'element'){
  cleanup();selection=new AbortController();const signal=selection.signal;const panel=document.createElement('div');panel.className='fc-selection';panel.tabIndex=-1;const box=document.createElement('div');box.className='fc-box';const tip=document.createElement('p');tip.textContent='FLEX Capture · Esc — cancel';panel.append(tip);surface().append(panel,box);let start:{x:number;y:number}|null=null;
  const rectangle=(x:number,y:number,w:number,h:number)=>{Object.assign(box.style,{left:`${x}px`,top:`${y}px`,width:`${w}px`,height:`${h}px`});};
  const elementAt=(x:number,y:number)=>{panel.style.pointerEvents='none';const el=document.elementFromPoint(x,y);panel.style.pointerEvents='auto';return el;};
  panel.addEventListener('pointermove',e=>{if(kind==='element'||e.ctrlKey){const el=elementAt(e.clientX,e.clientY);if(el){const r=el.getBoundingClientRect();rectangle(r.left,r.top,r.width,r.height);}}else if(start)rectangle(Math.min(e.clientX,start.x),Math.min(e.clientY,start.y),Math.abs(e.clientX-start.x),Math.abs(e.clientY-start.y));},{signal});
  panel.addEventListener('pointerdown',e=>{e.preventDefault();start={x:e.clientX,y:e.clientY};if(kind==='area')panel.setPointerCapture(e.pointerId);},{signal});
  panel.addEventListener('pointerup',e=>{if(!start)return;let r:{x:number;y:number;width:number;height:number};if(kind==='element'||e.ctrlKey){const el=elementAt(e.clientX,e.clientY);if(!el){cleanup();return;}const b=el.getBoundingClientRect();r={x:Math.max(0,b.left),y:Math.max(0,b.top),width:Math.min(innerWidth,b.right)-Math.max(0,b.left),height:Math.min(innerHeight,b.bottom)-Math.max(0,b.top)};}else r={x:Math.min(e.clientX,start.x),y:Math.min(e.clientY,start.y),width:Math.abs(e.clientX-start.x),height:Math.abs(e.clientY-start.y)};cleanup();if(r.width>2&&r.height>2)void send('capture.selection',{rect:r}).catch(()=>{});},{signal});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();cleanup();}},{capture:true,signal});panel.focus();
 }
 async function detect(batchId:string,high:boolean){if(auto)return;auto=true;const x=scrollX,y=scrollY,started=Date.now();let idle=0;const all=new Set<string>();const stop=document.createElement('button');stop.className='fc-stop';stop.textContent='Stop · FLEX Capture';stop.onclick=()=>{auto=false;};surface().append(stop);
 try{while(auto&&Date.now()-started<120000&&all.size<3000&&idle<5){const batch=await collect(high);const fresh=batch.assets.filter(a=>!all.has(a.id));for(const a of fresh)all.add(a.id);idle=fresh.length?0:idle+1;if(fresh.length)await send('capture.detected',{batchId,batch:{...batch,assets:fresh}});window.scrollBy(0,Math.round(innerHeight*.8));await new Promise(resolve=>setTimeout(resolve,900));}}finally{auto=false;stop.remove();window.scrollTo(x,y);if(!owner.signal.aborted)void send('capture.detectionFinished',{batchId}).catch(()=>{});}}
 ext.runtime.onMessage.addListener((message,sender,respond)=>{
  if(sender.id!==ext.runtime.id||!message||typeof message!=='object'||!('type'in message))return;const m=message as {type:string;high?:boolean;kind?:'area'|'element';y?:number;prefs?:Preferences;batchId?:string;url?:string};
  const result=async()=>{switch(m.type){
   case 'content.contextAsset':return contextAsset?.(m.url) ?? null;
   case 'content.collect':return collect(m.high!==false);
   case 'content.select':select(m.kind==='element'?'element':'area');return true;
   case 'content.auto':if(m.batchId)void detect(m.batchId,m.high!==false).catch(()=>{});return true;
   case 'content.stop':auto=false;return true;
   case 'content.metrics':return {width:innerWidth,height:innerHeight,scrollHeight:Math.max(document.body?.scrollHeight??0,document.documentElement.scrollHeight),x:scrollX,y:scrollY};
   case 'content.prepare':{restore();restoreScroll={x:scrollX,y:scrollY,hidden:[],behavior:document.documentElement.style.scrollBehavior};document.documentElement.style.scrollBehavior='auto';window.scrollTo(0,0);return true;}
   case 'content.scroll':{if(!restoreScroll)throw new Error('Capture not initialized');if((m.y??0)>0&&restoreScroll.hidden.length===0){let count=0;for(const el of document.querySelectorAll<HTMLElement>('body *')){if(count++>20000)break;const p=getComputedStyle(el).position;if(p==='fixed'||p==='sticky'){restoreScroll.hidden.push([el,el.style.visibility]);el.style.visibility='hidden';}}}window.scrollTo(0,Math.max(0,Number(m.y)||0));return {x:scrollX,y:scrollY};}
   case 'content.restore':restore();return true;
   case 'content.quick':if(m.prefs)enableQuick(m.prefs);return true;
   default:return null;}};
  void result().then(value=>respond({ok:true,data:value}),()=>respond({ok:false,error:'This page cannot be captured.'}));return true;
 });
 let disposeQuick:(()=>void)|null=null;
 let contextAsset: ReturnType<typeof installContextCapture> | undefined;
 function enableQuick(prefs:Preferences){
  disposeQuick?.();
  const contextOwner=new AbortController();
  const disposeDrag=installDrag(prefs);
  contextAsset=installContextCapture(prefs,contextOwner.signal);
  disposeQuick=()=>{contextOwner.abort();disposeDrag();};
 }
 state.flexCaptureDispose=()=>{auto=false;cleanup();restore();disposeQuick?.();owner.abort();host?.remove();delete state.flexCaptureDispose;};
 window.addEventListener('pagehide',()=>state.flexCaptureDispose?.(),{once:true,signal:owner.signal});
 void send<Preferences>('content.ready').then(enableQuick).catch(()=>{});
}
