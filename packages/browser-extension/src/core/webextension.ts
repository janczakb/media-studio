type Listener<A extends unknown[]=unknown[]>= {addListener(callback:(...args:A)=>unknown):void;removeListener(callback:(...args:A)=>unknown):void};
export interface Tab {id?:number;windowId:number;url?:string;title?:string;active?:boolean;}
export interface MessageSender {id?:string;url?:string;tab?:Tab;frameId?:number;}
interface StorageArea {get(keys?:string|string[]|null):Promise<Record<string,unknown>>;set(value:Record<string,unknown>):Promise<void>;remove(key:string|string[]):Promise<void>;setAccessLevel?(value:{accessLevel:'TRUSTED_CONTEXTS'}):Promise<void>;}
export interface WebExtension {
 runtime:{id:string;getURL(path:string):string;sendMessage<T=unknown>(message:unknown):Promise<T>;onMessage:Listener<[unknown,MessageSender,(response:unknown)=>void]>;onInstalled:Listener<[]>;};
 storage:{local:StorageArea;session?:StorageArea;};
 tabs:{query(query:Record<string,unknown>):Promise<Tab[]>;get(id:number):Promise<Tab>;create(options:{url:string;active?:boolean}):Promise<Tab>;sendMessage<T=unknown>(id:number,message:unknown):Promise<T>;captureVisibleTab(windowId:number,options:{format:'png'|'jpeg';quality?:number}):Promise<string>;onRemoved:Listener<[number]>;};
 scripting:{executeScript<T=unknown>(options:{target:{tabId:number;allFrames?:boolean};files?:string[];func?:(...args:never[])=>unknown;args?:unknown[]}):Promise<{frameId:number;result?:T}[]>;registerContentScripts(scripts:Record<string,unknown>[]):Promise<void>;unregisterContentScripts(options:{ids:string[]}):Promise<void>;};
 permissions:{contains(options:{origins:string[]}):Promise<boolean>;request(options:{origins:string[]}):Promise<boolean>;remove(options:{origins:string[]}):Promise<boolean>;};
 contextMenus:{create(options:Record<string,unknown>):void;removeAll():Promise<void>;onClicked:Listener<[{menuItemId:string;srcUrl?:string;pageUrl?:string;selectionText?:string;linkUrl?:string;mediaType?:string},Tab|undefined]>;};
 commands:{onCommand:Listener<[string,Tab|undefined]>;};
 alarms:{create(name:string,options:{delayInMinutes?:number;periodInMinutes?:number}):Promise<void>;onAlarm:Listener<[{name:string}]>;};
 action:{openPopup?():Promise<void>;setBadgeText(options:{text:string}):Promise<void>;setBadgeBackgroundColor(options:{color:string}):Promise<void>;};
}
export const ext=((globalThis as unknown as {browser?:WebExtension;chrome?:WebExtension}).browser??(globalThis as unknown as {chrome:WebExtension}).chrome);
export async function send<T>(type:string,data:Record<string,unknown>={}):Promise<T>{const r=await ext.runtime.sendMessage<{ok:boolean;data?:T;error?:string}>({type,...data});if(!r?.ok)throw new Error(r?.error??'Extension unavailable.');return r.data as T;}
