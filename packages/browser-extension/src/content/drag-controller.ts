import {assetsFromUriList} from './drag-assets';
import {shortcutActions,shortcutFromEvent} from '../core/shortcuts';
import {send} from '../core/webextension';
import type {Preferences} from '../core/types';
import {instagramPostForVideo, instagramVideo} from './instagram';
import {extractAsset} from './collector';
import {shouldOpen,type Point} from './drag-geometry';
import type {DragWorld} from './drag-types';

export function installDrag(prefs:Preferences):()=>void {
 const owner=new AbortController(),signal=owner.signal;const world=globalThis as DragWorld;
 let dragging:AbortController|null=null,preview:HTMLCanvasElement|null=null,fallback:ReturnType<typeof setTimeout>|undefined,outside:ReturnType<typeof setTimeout>|undefined;
 let start:Point={x:0,y:0},point:Point={x:0,y:0},changes=0,opening=false,opened=false;
 function finish(force=true){dragging?.abort();dragging=null;clearTimeout(fallback);clearTimeout(outside);preview?.remove();preview=null;if(force||!world.flexCaptureDrag?.retain)world.flexCaptureDrag?.close();opened=false;opening=false;}
 const startDrag=(event:DragEvent)=>{
  if(prefs.quickMode==='off'||prefs.excludedHosts.includes(location.hostname)||prefs.excludedUrls.includes(location.href))return;
  const target=event.composedPath().find(n=>n instanceof Element) as Element|undefined;if(!target||target.closest('[contenteditable="true"],input,textarea,[data-flex-capture]'))return;
  const a=extractAsset(target,prefs.highResolution)|| (target.closest('a[href]')?extractAsset(target.closest('a[href]')!,prefs.highResolution):null);
  const post=!a&&target instanceof HTMLVideoElement?instagramPostForVideo(target):null;
  if(!a&&!post)return;
  const uriList=event.dataTransfer?.getData('text/uri-list')??'';
  finish();dragging=new AbortController();const session=dragging,opts={signal:session.signal};start={x:event.clientX,y:event.clientY};point=start;changes=0;
  if(target instanceof HTMLImageElement&&target.naturalWidth>140){preview=document.createElement('canvas');preview.width=140;preview.height=Math.max(1,Math.round(140*target.naturalHeight/target.naturalWidth));preview.style.cssText='position:fixed;left:-10000px;top:-10000px;pointer-events:none';preview.getContext('2d')?.drawImage(target,0,0,preview.width,preview.height);document.documentElement.append(preview);event.dataTransfer?.setDragImage(preview,0,0);}
  const open=async()=>{if(opening||opened||session.signal.aborted)return;opening=true;try{const asset=a??(post?await instagramVideo(post,prefs.highResolution):null);if(!asset||session.signal.aborted)return;
   let assets=assetsFromUriList(uriList,asset);
   const selected=target.closest('[aria-selected="true"]'),group=selected?.closest('[role="grid"],[role="listbox"]');
   if(group){const rows=group.querySelectorAll('[aria-selected="true"]');if(rows.length>300)return;const picked=Array.from(rows).map(row=>extractAsset(row.querySelector('img,video,audio,a[href]')??row,prefs.highResolution)).filter((value):value is NonNullable<typeof value>=>!!value);if(picked.length>1)assets=[...new Map(picked.map(value=>[value.url,value])).values()];}
   await send('drag.prepare');if(session.signal.aborted)return;world.flexCaptureDrag?.open(assets,start,point,prefs);opened=true;}catch{}finally{opening=false;}};
  const move=(e:DragEvent)=>{if(e.clientX===0&&e.clientY===0)return;const next={x:e.clientX,y:e.clientY};if(next.x!==point.x||next.y!==point.y){point=next;changes++;if(shouldOpen(changes,start,point))void open();clearTimeout(fallback);fallback=setTimeout(()=>void open(),800);}clearTimeout(outside);outside=setTimeout(finish,2000);};
  target.addEventListener('drag',move as EventListener,opts);document.addEventListener('dragover',move,opts);document.addEventListener('dragend',()=>finish(false),{...opts,once:true});document.addEventListener('drop',()=>{setTimeout(()=>finish(false),0);},{...opts,once:true});document.addEventListener('keydown',e=>{if(e.key==='Escape')finish();},opts);
 };
 document.addEventListener('dragstart',startDrag,{signal});
 document.addEventListener('keydown',event=>{
   if(event.repeat||event.isComposing||event.defaultPrevented||prefs.excludedHosts.includes(location.hostname)||prefs.excludedUrls.includes(location.href))return;
   if(event.composedPath().some(element=>element instanceof Element&&element.matches('input,textarea,select,[contenteditable]:not([contenteditable="false"])')))return;
   const binding=shortcutFromEvent(event);
   const action=binding&&shortcutActions.find(action=>prefs.shortcuts[action]===binding);
   if(!action)return;
   event.preventDefault();
   void send('content.shortcut',{action}).catch(()=>{});
 },{signal});
 return ()=>{finish();owner.abort();};
}
