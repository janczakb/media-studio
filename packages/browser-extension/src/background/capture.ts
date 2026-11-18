import {ext,type Tab} from '../core/webextension';
import {rememberBatch,read} from '../core/storage';
import {httpUrl,safeName} from '../core/urls';
import type {CaptureBatch} from '../core/types';
import {preferences} from './settings';
import {persistBlob} from './queue';
export async function activeTab():Promise<Tab>{const tab=(await ext.tabs.query({active:true,currentWindow:true}))[0];if(!tab?.id||!httpUrl(tab.url??''))throw new Error('Open a normal website first. Browser settings and extension pages cannot be captured.');return tab;}
export async function content<T>(tabId:number,type:string,values:Record<string,unknown>={}):Promise<T>{const response=await ext.tabs.sendMessage<{ok:boolean;data?:T;error?:string}>(tabId,{type,...values});if(!response?.ok)throw new Error(response?.error??'Page unavailable.');return response.data as T;}
export async function inject(tab:Tab):Promise<void>{if(!tab.id)throw new Error('Tab unavailable.');await ext.scripting.executeScript({target:{tabId:tab.id},files:['content.js']});}
export async function openManager(id?:string):Promise<void>{await ext.tabs.create({url:ext.runtime.getURL('manager.html')+(id?'?batch='+encodeURIComponent(id):'')});}
export async function collectTab(tab:Tab,automatic=false):Promise<void>{await inject(tab);const p=await preferences();const batch=await content<CaptureBatch>(tab.id!,'content.collect',{high:p.highResolution});await rememberBatch(batch);if(automatic)await content(tab.id!,'content.auto',{batchId:batch.id,high:p.highResolution});else await openManager(batch.id);}
export async function verifyVisible(tab:Tab):Promise<void>{const current=(await ext.tabs.query({active:true,windowId:tab.windowId}))[0];const fresh=await ext.tabs.get(tab.id!);if(current?.id!==tab.id||fresh.url!==tab.url)throw new Error('Capture stopped because the active tab changed.');}
let capturing=false;
export async function screenshot(tab:Tab,mode:'visible'|'full',rect?:{x:number;y:number;width:number;height:number}):Promise<void>{
 if(capturing)throw new Error('A screenshot is already running.');capturing=true;let prepared=false;let canvas:OffscreenCanvas|null=null;
 try{await inject(tab);await verifyVisible(tab);const p=await preferences();const m=await content<{width:number;height:number;scrollHeight:number}>(tab.id!,'content.metrics');
 const totalHeight=mode==='full'?m.scrollHeight:m.height;const tiles=mode==='full'?Math.ceil(totalHeight/m.height):1;if(tiles>48)throw new Error('This page is too tall. Capture a selected area instead.');
 if(mode==='full'){await content(tab.id!,'content.prepare');prepared=true;}
 let pixelScale=1;let outputScale=1;
 for(let tile=0;tile<tiles;tile++){
 let top=0;if(mode==='full'){const position=await content<{y:number}>(tab.id!,'content.scroll',{y:tile*m.height});top=position.y;}
 await new Promise(resolve=>setTimeout(resolve,Math.max(550,Math.min(3000,p.scrollDelay))));await verifyVisible(tab);
 const raw=await ext.tabs.captureVisibleTab(tab.windowId,{format:'png'});const bitmap=await createImageBitmap(await(await fetch(raw)).blob());
 try{if(tile===0){pixelScale=bitmap.width/m.width;outputScale=p.retina?pixelScale:1;const w=Math.round((rect?.width??m.width)*outputScale),h=Math.round((rect?.height??totalHeight)*outputScale);if(w<=0||h<=0||w*h>32_000_000||w>32767||h>32767)throw new Error('Screenshot exceeds the 32 megapixel safety limit.');canvas=new OffscreenCanvas(w,h);}
 const ctx=canvas!.getContext('2d');if(!ctx)throw new Error('Screenshot renderer unavailable.');
 if(rect){ctx.drawImage(bitmap,rect.x*pixelScale,rect.y*pixelScale,rect.width*pixelScale,rect.height*pixelScale,0,0,canvas!.width,canvas!.height);}
 else {const sourceHeight=Math.min(m.height,totalHeight-top);ctx.drawImage(bitmap,0,0,bitmap.width,sourceHeight*pixelScale,0,top*outputScale,canvas!.width,sourceHeight*outputScale);}
 }finally{bitmap.close();}
 }
 if(!canvas)throw new Error('Screenshot unavailable.');const blob=await canvas.convertToBlob({type:p.screenshotFormat==='jpeg'?'image/jpeg':'image/png',quality:.9});
 const id=crypto.randomUUID();await persistBlob(id,blob);const batch:CaptureBatch={id,title:tab.title??'Screenshot',url:tab.url!,createdAt:Date.now(),truncated:false,assets:[{id,url:tab.url!,previewUrl:null,localBlobId:id,name:safeName(tab.title??'Screenshot')+(p.screenshotFormat==='jpeg'?'.jpg':'.png'),kind:'image',width:canvas.width,height:canvas.height,pageUrl:tab.url!,description:''}]};await rememberBatch(batch);await openManager(id);
 }finally{canvas=null;if(prepared)await content(tab.id!,'content.restore').catch(()=>{});capturing=false;}
}
