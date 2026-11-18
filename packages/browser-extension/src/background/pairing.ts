import {ext} from '../core/webextension';
import {allowedApiBase,randomSecret,serverUrl,sha256} from '../core/urls';
import type {Connection,Pairing} from '../core/types';
import {connections,pairing} from './settings';
export async function startPairing(input:string):Promise<Omit<Pairing,'verifier'>>{
 const u=serverUrl(input);let apiBase=u.href.replace(/\/$/,'');
 if(!u.pathname.replace(/\/$/,'').endsWith('/extension/v1')){const discovery=await fetch(u.origin+'/.well-known/flex-media-studio.json',{credentials:'omit',redirect:'error',signal:AbortSignal.timeout(15000)});if(!discovery.ok)throw new Error('Enable the browser integration in Studio.');const info=await discovery.json() as {apiBase:string;protocol:number};if(info.protocol!==1)throw new Error('Unsupported Studio capture protocol.');apiBase=allowedApiBase(info.apiBase,u.origin);}
 // Input is the extension API endpoint, not an assumed path on every host installation.
 const verifier=randomSecret();const response=await fetch(apiBase+'/devices',{method:'POST',credentials:'omit',redirect:'error',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({challenge:await sha256(verifier),name:typeof navigator!=='undefined'&&/Firefox/.test(navigator.userAgent)?'Firefox':'Chrome'}),signal:AbortSignal.timeout(20000)});
 const data=await response.json() as {id:string;code:string;verificationUrl:string;expiresIn:number};if(!response.ok||!data.id||!data.code)throw new Error('Cannot connect. Enable the browser integration in Studio and check the API address.');
 const approval=new URL(data.verificationUrl);if(approval.origin!==u.origin||!/^https?:$/.test(approval.protocol))throw new Error('Unexpected approval address.');
 const value:Pairing={...data,apiBase,verifier,expiresAt:Date.now()+600000,nextPollAt:Date.now()+5000};await ext.storage.local.set({pairing:value});await ext.tabs.create({url:approval.href});await ext.alarms.create('flex-capture-pairing',{delayInMinutes:0.5,periodInMinutes:0.5});return publicPairing(value);
}
export function publicPairing(p:Pairing):Omit<Pairing,'verifier'>{const {verifier,...publicValue}=p;return publicValue;}
let polling=false;
export async function pollPairing():Promise<void>{
 if(polling)return;polling=true;try{const p=await pairing();if(!p)return;if(p.expiresAt<Date.now()){await ext.storage.local.remove('pairing');return;}if(p.nextPollAt>Date.now())return;await ext.storage.local.set({pairing:{...p,nextPollAt:Date.now()+5500}});
 const response=await fetch(p.apiBase+'/devices/claim',{method:'POST',credentials:'omit',redirect:'error',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({id:p.id,verifier:p.verifier}),signal:AbortSignal.timeout(15000)});
 if(response.status===202||response.status===429)return;if(!response.ok){await ext.storage.local.remove('pairing');throw new Error('Connection request expired. Start again.');}
 const d=await response.json() as Omit<Connection,'id'>;if(!/^fms_ext_[A-Z0-9]{26}\.[a-f0-9]{64}$/.test(d.token)||!d.workspace?.id)throw new Error('Invalid connection response.');
 const c:Connection={...d,id:crypto.randomUUID(),apiBase:allowedApiBase(d.apiBase,new URL(p.apiBase).origin)};const saved=await connections();await ext.storage.local.set({connections:[...saved.filter(v=>v.apiBase!==c.apiBase||v.workspace.id!==c.workspace.id),c],pairing:null});
 }finally{polling=false;}
}
