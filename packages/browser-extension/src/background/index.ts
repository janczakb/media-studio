import {captureStatus,summarizeCaptures} from '../core/capture-status';
import {pinterestOriginal} from '../core/pinterest-image';
import {normalizeShortcuts,shortcutActions} from '../core/shortcuts';
import {ext,type MessageSender,type Tab} from '../core/webextension';
import {admitJobs,list,read,readMany,rememberBatch,save} from '../core/storage';
import {httpUrl,inferKind,permissionPattern,safeName} from '../core/urls';
import type {Asset,CaptureBatch,Preferences,Job,Snapshot} from '../core/types';
import {connections,connection,pairing,preferences} from './settings';
import {api,workspacePath} from './api';
import {pollPairing,publicPairing,startPairing} from './pairing';
import {action,clearFinished,drain,enqueue} from './queue';
import {activeTab,collectTab,content,inject,openManager,screenshot} from './capture';

function object(value:unknown):Record<string,unknown>{if(!value||typeof value!=='object'||Array.isArray(value))throw new Error('Invalid message.');return value as Record<string,unknown>;}
const string=(v:unknown,max=8192)=>{if(typeof v!=='string'||v.length>max)throw new Error('Invalid input.');return v;};
function asset(value:unknown):Asset {const v=object(value);const url=httpUrl(string(v.url));const pageUrl=httpUrl(string(v.pageUrl));if(!url||!pageUrl||!['image','video','audio','document'].includes(String(v.kind)))throw new Error('Unsupported capture.');const inline=typeof v.inline==='string'&&v.inline.length<=700000&&/^data:image\/png;base64,[a-zA-Z0-9+/=]+$/.test(v.inline)?v.inline:undefined;return {id:inline&&typeof v.id==='string'?string(v.id,100):url,url,pageUrl,previewUrl:inline??(v.previewUrl?httpUrl(string(v.previewUrl)):null),...(inline?{inline}:{}),...(typeof v.fallbackUrl==='string'&&httpUrl(v.fallbackUrl)?{fallbackUrl:httpUrl(v.fallbackUrl)!}:{}),tags:Array.isArray(v.tags)?v.tags.slice(0,32).map(tag=>string(tag,120)):[],name:safeName(string(v.name,500)),kind:v.kind as Asset['kind'],width:Math.max(0,Math.min(100000,Number(v.width)||0)),height:Math.max(0,Math.min(100000,Number(v.height)||0)),description:typeof v.description==='string'?v.description.slice(0,4000):''};}
async function quick(value: Asset): Promise<void> {
 const [p, saved] = await Promise.all([preferences(), connections()]);
 const c = saved.find(item => Date.parse(item.expiresAt) > Date.now());
 const hasSourceAccess = value.inline || await ext.permissions.contains({origins: [permissionPattern(value.url)]});
 const batch: CaptureBatch = {
  id: crypto.randomUUID(), url: value.pageUrl, title: value.name,
  assets: [value], createdAt: Date.now(), truncated: false,
 };
 if (p.askDestination || !c || !hasSourceAccess) {
  await rememberBatch(batch);
  await openManager(batch.id);
 } else {
  await enqueue(batch.assets, c.id, null);
 }
}
async function ownMessage(m:Record<string,unknown>):Promise<unknown>{switch(m.type){
 case 'snapshot':{await pollPairing().catch(()=>{});const p=await pairing();return {connections:(await connections()).map(({token,...c})=>c),preferences:await preferences(),jobs:await list<Job>('jobs'),pairing:p?publicPairing(p):null} satisfies Snapshot;}
 case 'pair':return startPairing(string(m.apiBase));
 case 'pair.cancel':await ext.storage.local.remove('pairing');return true;
 case 'disconnect':{const id=string(m.id,100),c=await connection(id);await api(c,'/connection','DELETE');await ext.storage.local.set({connections:(await connections()).filter(v=>v.id!==id)});return true;}
 case 'collect':await collectTab(await activeTab(),m.automatic===true);return true;
 case 'capture':{const tab=await activeTab();if(m.mode==='visible'||m.mode==='full')await screenshot(tab,m.mode);else {await inject(tab);await content(tab.id!,'content.select',{kind:m.mode==='element'?'element':'area'});}return true;}
 case 'manager':await openManager();return true;
 case 'folders':return folderPage(m);
 case 'tags':{const c=await connection(string(m.connectionId,100));return api(c,workspacePath(c,'tags')+'?'+new URLSearchParams({search:string(m.search,100)}));}
 case 'connection.info':{const c=await connection(string(m.connectionId,100));return api(c,'/connection');}
 case 'folder.create':return createFolder(m);
 case 'enqueue':{const batch=await read<CaptureBatch>('batches',string(m.batch,100));if(!batch)throw new Error('Collection expired. Collect this page again.');if(!Array.isArray(m.ids)||m.ids.length>300)throw new Error('Select at most 300 files.');const ids=new Set(m.ids);const selected=batch.assets.filter(a=>ids.has(a.id));return enqueue(selected,string(m.connectionId,100),m.parent?string(m.parent,26):null,Array.isArray(m.tags)?m.tags.map(v=>string(v,120)).slice(0,32):[],typeof m.description==='string'?string(m.description,4000):'',Array.isArray(m.tagRefs)?m.tagRefs.slice(0,32).map(value=>{const tag=object(value);return tag.id?{id:string(tag.id,26)}:{name:string(tag.name,120)};}):[]);}
 case 'job':{if(!['pause','resume','retry','cancel'].includes(String(m.operation)))throw new Error('Invalid operation');await action(string(m.id,100),m.operation as 'pause'|'resume'|'retry'|'cancel');return true;}
 case 'jobs.all':{const op=string(m.operation,12);if(!['pause','resume'].includes(op))throw new Error('Invalid operation');for(const job of await list<Job>('jobs'))await action(job.id,op as 'pause'|'resume');return true;}
 case 'jobs.clear':await clearFinished();return true;
 case 'preferences':{const v=object(m.value),current=await preferences();const p:Preferences={...current,locale:v.locale==='pl'?'pl':'en',theme:['light','dark','system'].includes(String(v.theme))?v.theme as Preferences['theme']:current.theme,highResolution:v.highResolution===true,askDestination:v.askDestination===true,retina:v.retina===true,altRightClick:v.altRightClick===true,quickMode:['off','dock','popup'].includes(String(v.quickMode))?v.quickMode as Preferences['quickMode']:'off',screenshotFormat:v.screenshotFormat==='jpeg'?'jpeg':'png',scrollDelay:Math.max(500,Math.min(3000,Number(v.scrollDelay)||600)),maxDownloadBytes:Math.max(1048576,Math.min(128*1048576,Number(v.maxDownloadBytes)||current.maxDownloadBytes)),concurrency:Math.floor(Math.max(1,Math.min(3,Number(v.concurrency)||2))),shortcuts:normalizeShortcuts(v.shortcuts),browserSession:v.browserSession===true,excludedUrls:Array.isArray(v.excludedUrls)?v.excludedUrls.map(x=>string(x)).slice(0,200):current.excludedUrls,excludedHosts:Array.isArray(v.excludedHosts)?v.excludedHosts.map(x=>string(x,253)).slice(0,200):current.excludedHosts};await ext.storage.local.set({preferences:p});
  const tabs=await ext.tabs.query({});
  await Promise.allSettled(tabs.filter(tab=>tab.id&&tab.url&&httpUrl(tab.url)).map(tab=>content(tab.id!,'content.quick',{prefs:p})));
  return p;}
 case 'quick.enable':{if(m.all===true){if(!await ext.permissions.contains({origins:['http://*/*','https://*/*']}))throw new Error('Allow websites first.');await ext.scripting.unregisterContentScripts({ids:['quick-all']}).catch(()=>{});await ext.scripting.registerContentScripts([{id:'quick-all',matches:['http://*/*','https://*/*'],js:['content.js'],runAt:'document_idle'}]);return true;}const tab=await activeTab();const origin=permissionPattern(tab.url!);if(!await ext.permissions.contains({origins:[origin]}))throw new Error('Allow this website first.');const key='quick-'+new URL(tab.url!).hostname.replace(/[^a-z0-9]/gi,'_');await ext.scripting.unregisterContentScripts({ids:[key]}).catch(()=>{});await ext.scripting.registerContentScripts([{id:key,matches:[origin],js:['content.js'],runAt:'document_idle'}]);await inject(tab);return true;}
 case 'bookmark':return saveBookmark(await activeTab(), string(m.connectionId,100));
 case 'board':await collectTab(await activeTab(),true);return true;
 default:throw new Error('Unknown action.');}}
async function folderPage(message:Record<string,unknown>){
  const c=await connection(string(message.connectionId,100));
  const params=new URLSearchParams();
  if(message.parent)params.set('parent',string(message.parent,26));
  if(message.after)params.set('after',string(message.after,26));
  if(message.before)params.set('before',string(message.before,26));
  if(message.search)params.set('search',string(message.search,100));
  return api(c,workspacePath(c,'folders')+'?'+params);
}

async function createFolder(message:Record<string,unknown>){
  const c=await connection(string(message.connectionId,100));
  return api(c,workspacePath(c,'folders'),'POST',{name:string(message.name,191),parent:message.parent?string(message.parent,26):null});
}

async function saveBookmark(tab:Tab, connectionId?:string, selectedText?:string):Promise<unknown> {
  const id=connectionId||(await connections())[0]?.id;
  if(!id)throw new Error('Connect Studio first.');
  const url=httpUrl(tab.url??'');
  if(!url)throw new Error('Unsupported page.');
  const c=await connection(id);
  if(selectedText===undefined&&tab.id) {
    const result=await ext.scripting.executeScript<string>({target:{tabId:tab.id},func:()=>window.getSelection()?.toString().slice(0,20000)??''});
    selectedText=result[0]?.result;
  }
  const text=selectedText?.slice(0,20000)||tab.title||url;
  return api(c,workspacePath(c,'notes'),'POST',{key:crypto.randomUUID(),title:safeName(tab.title??'Bookmark'),background:'#ffffff',document:{type:'doc',content:[{type:'paragraph',content:[{type:'text',text}]},{type:'paragraph',content:[{type:'text',text:url,marks:[{type:'link',attrs:{href:url}}]}]}]}});
}
async function contentMessage(m:Record<string,unknown>,sender:MessageSender):Promise<unknown>{const tab=sender.tab!;if(!tab.id||!httpUrl(tab.url??''))throw new Error('Unsupported page.');
 switch(m.type){case 'content.ready':return preferences();
 case 'content.shortcut':{
  const prefs=await preferences();
  if(prefs.excludedHosts.includes(new URL(tab.url!).hostname)||prefs.excludedUrls.includes(tab.url!))return false;
  const action=shortcutActions.find(action=>action===m.action);
  if(action==='bookmark')await saveBookmark(tab);
  else if(action==='collect')await collectTab(tab);
  else if(action==='area')await content(tab.id,'content.select',{kind:'area'});
  else if(action==='visible'||action==='full')await screenshot(tab,action);
  return !!action;
 }
 case 'drag.prepare':await ext.scripting.executeScript({target:{tabId:tab.id},files:['drag-ui.js']});return true;
 case 'drag.folders':return folderPage(m);
 case 'drag.tags':{const c=await connection(string(m.connectionId,100));const query=new URLSearchParams({search:string(m.search,100)});return api(c,workspacePath(c,'tags')+'?'+query);}
 case 'drag.folderCreate':return createFolder(m);
 case 'drag.context':{
  const saved=(await connections()).filter(c=>Date.parse(c.expiresAt)>Date.now());
  const id=typeof m.connectionId==='string'&&m.connectionId?m.connectionId:saved[0]?.id;
  if(!id)return {connectionId:null,workspace:null,folders:[],connections:[]};
  const c=await connection(id);
  const info=await api<{capabilities:{folders:boolean;workspaces?:boolean}}>(c,'/connection');
  return {connectionId:c.id,workspace:c.workspace.name,canCreate:info.capabilities.folders,workspacesEnabled:info.capabilities.workspaces===true,connections:saved.filter(item=>item.apiBase===c.apiBase).map(item=>({id:item.id,name:item.workspace.name}))};
 }
 case 'drag.status':{
  const id=string(m.connectionId,100);await connection(id);
  if(Array.isArray(m.jobIds)){if(!m.jobIds.length||m.jobIds.length>300)throw new Error('Invalid capture batch.');const ids=[...new Set(m.jobIds.map(value=>string(value,100)))];const jobs=await readMany<Job>('jobs',ids);return summarizeCaptures(jobs.map(job=>captureStatus(job,id,tab.url!)));}
  return captureStatus(await read<Job>('jobs',string(m.jobId,100)),id,tab.url!);
 }
 case 'drag.save':{
  const values=Array.isArray(m.assets)?m.assets:[m.asset];if(!values.length||values.length>300)throw new Error('Select between 1 and 300 files.');
  const assets=[...new Map(values.map(value=>{const item=asset(value);return [item.url,item] as const;})).values()];
  const p=await preferences();if(p.quickMode==='off')throw new Error('Drag capture is disabled.');const id=string(m.connectionId,100);await connection(id);
  const origins=[...new Set(assets.filter(item=>!item.inline).map(item=>permissionPattern(item.url)))];
  if(origins.length&&!await ext.permissions.contains({origins})){
   const batch:CaptureBatch={id:crypto.randomUUID(),url:tab.url!,title:assets[0]!.name,assets,createdAt:Date.now(),truncated:false};await rememberBatch(batch);await openManager(batch.id);return {confirmation:true};
  }
  return enqueue(assets,id,m.parent?string(m.parent,26):null,Array.isArray(m.tags)?m.tags.map(value=>string(value,120)):[],typeof m.description==='string'?string(m.description,4000):'',Array.isArray(m.tagRefs)?m.tagRefs.slice(0,32).map(value=>{const tag=object(value);return tag.id?{id:string(tag.id,26)}:{name:string(tag.name,120)};}):[]);
 }
 case 'capture.selection':{const r=object(m.rect);const rect={x:Number(r.x),y:Number(r.y),width:Number(r.width),height:Number(r.height)};if(!Object.values(rect).every(Number.isFinite)||rect.x<0||rect.y<0||rect.width<1||rect.height<1||rect.width>10000||rect.height>10000)throw new Error('Invalid capture area.');await screenshot(tab,'visible',rect);return true;}
 case 'quick.capture':{const p=await preferences();if(!p.altRightClick)throw new Error('Alt + right-click is disabled.');await quick(asset(m.asset));return true;}
 case 'capture.detected':{const b=object(m.batch);if(!Array.isArray(b.assets)||b.assets.length>3000)throw new Error('Invalid batch.');const incoming=b.assets.map(asset);const target=await read<CaptureBatch>('batches',string(m.batchId,100));if(!target||target.url!==tab.url)return false;const items=new Map([...target.assets,...incoming].map(a=>[a.id,a]));target.assets=[...items.values()].slice(0,3000);target.truncated=items.size>3000;await rememberBatch(target);return true;}
 case 'capture.detectionFinished':{const id=string(m.batchId,100),batch=await read<CaptureBatch>('batches',id);if(!batch||batch.url!==tab.url)return false;await openManager(id);return true;}
 default:throw new Error('This message is not available to web pages.');}}
ext.runtime.onMessage.addListener((message,sender,respond)=>{if(sender.id!==ext.runtime.id)return;let m:Record<string,unknown>;try{m=object(message);}catch{return;}const own=!sender.tab&&sender.url?.startsWith(ext.runtime.getURL(''))||sender.url?.startsWith(ext.runtime.getURL('manager.html'))||sender.url?.startsWith(ext.runtime.getURL('popup.html'));
 const task=own?ownMessage(m):sender.tab?contentMessage(m,sender):Promise.reject(new Error('Untrusted message.'));
 void task.then(data=>respond({ok:true,data}),e=>respond({ok:false,error:e instanceof Error?e.message:'Capture failed.'}));return true;});
ext.runtime.onInstalled.addListener(() => {
 void ext.contextMenus.removeAll().then(() => {
  const menus = [
   ['save', 'Save file to FLEX Capture', ['image', 'video', 'audio', 'link']],
   ['bookmark', 'Save URL', ['page', 'link']],
   ['selection', 'Save selected text', ['selection']],
   ['batch', 'Batch save', ['page']],
   ['area', 'Capture area', ['page']],
   ['visible', 'Capture visible', ['page']],
   ['full', 'Capture page', ['page']],
  ] as const;
  for (const [id, title, contexts] of menus) {
   ext.contextMenus.create({id, title, contexts: [...contexts], documentUrlPatterns: ['http://*/*', 'https://*/*']});
  }
 });
 void ext.alarms.create('flex-capture-queue', {periodInMinutes: 0.5});
});

ext.contextMenus.onClicked.addListener((info, tab) => {
 if (!tab?.id) return;
 void (async () => {
  switch (info.menuItemId) {
   case 'batch': return collectTab(tab);
   case 'visible': return screenshot(tab, 'visible');
   case 'full': return screenshot(tab, 'full');
   case 'area':
    await inject(tab);
    return content(tab.id!, 'content.select', {kind: 'area'});
   case 'bookmark':
    return saveBookmark(info.linkUrl ? {...tab, url: info.linkUrl, title: info.linkUrl, id: undefined} : tab);
   case 'selection':
    return saveBookmark(tab, undefined, info.selectionText);
   case 'save': {
    const sourceUrl = info.srcUrl || info.linkUrl || '';
    const pageUrl = httpUrl(info.pageUrl || tab.url || '');
    if (!pageUrl) throw new Error('This source cannot be captured.');
    const url = httpUrl(sourceUrl);
    const inspected = await content<Asset | null>(tab.id!, 'content.contextAsset', {url: url ?? sourceUrl}).catch(() => null);
    if (inspected) return quick(asset(inspected));
    if (!url) throw new Error('This source cannot be captured.');
    const kind = info.mediaType === 'image' || info.mediaType === 'video' || info.mediaType === 'audio'
     ? info.mediaType : inferKind(url);
    if (!kind) throw new Error('Use Save URL for a link to a web page.');
    const original = kind === 'image' && (await preferences()).highResolution ? pinterestOriginal(url) : null;
    const source = original ?? url;
    return quick({
     id: source, url: source, previewUrl: kind === 'image' ? url : null,
     ...(source !== url ? {fallbackUrl: url} : {}),
     name: safeName(new URL(url).pathname.split('/').pop() ?? 'capture'),
     kind, width: 0, height: 0, pageUrl, description: '',
    });
   }
  }
 })().catch(() => {void ext.action.setBadgeText({text: '!'});});
});
ext.commands.onCommand.addListener((command,tab)=>{void(async()=>{const t=tab??await activeTab();if(command==='collect-page')await collectTab(t);if(command==='capture-area'){await inject(t);await content(t.id!,'content.select',{kind:'area'});}if(command==='capture-visible')await screenshot(t,'visible');})().catch(()=>{});});
ext.alarms.onAlarm.addListener(a=>{if(a.name==='flex-capture-pairing')void pollPairing().catch(()=>{});if(a.name==='flex-capture-queue')void drain();});
void ext.storage.local.setAccessLevel?.({accessLevel:'TRUSTED_CONTEXTS'}).catch(()=>{});
void drain();
