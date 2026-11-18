import {finishTreeOperation,type TreeOperationResult} from './tree-operations';
import {randomId} from './random-id';
import type {FolderContext,AncestorPage,NavigationChangePage} from './folders/contracts';
import type {Entry,EntryDetails,EntryPage} from './contracts';

export class StudioApiError extends Error {
  constructor(readonly status:number,message:string,readonly retryAfter:number|null=null){super(message);this.name='StudioApiError';}
}
export interface StudioApiOptions {
  baseUrl:string;
  workspace:string;
  /** Server-side secret. Never expose it in a public frontend bundle. */
  token:string|(()=>string|Promise<string>);
  timeoutMs?:number;
  fetch?:typeof globalThis.fetch;
}
export interface StudioApiRequest {method?:'GET'|'POST'|'PATCH'|'PUT'|'DELETE';body?:BodyInit;headers?:HeadersInit;signal?:AbortSignal}

/** A dependency-free, workspace-bound REST client. Mutations are never automatically retried. */
export function createStudioApi(options:StudioApiOptions){
  const base=new URL(options.baseUrl);
  if(!['http:','https:'].includes(base.protocol)||base.username||base.password||base.search||base.hash)throw new Error('A clean HTTP(S) API base URL is required.');
  if(!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(options.workspace))throw new Error('A workspace ULID is required.');
  const root=base.href.replace(/\/$/,'')+'/workspaces/'+options.workspace;
  const send=options.fetch??globalThis.fetch;
  const timeout=Number.isFinite(options.timeoutMs)?Math.max(1000,Math.min(300000,options.timeoutMs!)):30000;
  const id=(value:string)=>{if(!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(value))throw new Error('A resource ULID is required.');return value;};
  async function request<T>(path:string,input:StudioApiRequest={}):Promise<T>{
    if(!/^(?:[a-zA-Z0-9_-]+\/?)*$/.test(path.split('?')[0]!)||path.includes('#'))throw new Error('A relative workspace API path is required.');
    const token=typeof options.token==='function'?await options.token():options.token;
    if(!token)throw new Error('An API token is required.');
    const headers=new Headers(input.headers);headers.set('Accept','application/json');headers.set('Authorization','Bearer '+token);
    const signal=input.signal?AbortSignal.any([input.signal,AbortSignal.timeout(timeout)]):AbortSignal.timeout(timeout);
    const response=await send(root+(path?'/'+path:''),{...input,headers,signal,credentials:'omit',redirect:'error',cache:'no-store'});
    const reader=response.body?.getReader();const chunks:Uint8Array[]=[];let bytes=0;
    try{if(reader)while(true){const chunk=await reader.read();if(chunk.done)break;bytes+=chunk.value.byteLength;if(bytes>4*1024*1024){await reader.cancel();throw new Error('API response exceeds the client limit.');}chunks.push(chunk.value);}}finally{reader?.releaseLock();}
    const all=new Uint8Array(bytes);let offset=0;for(const chunk of chunks){all.set(chunk,offset);offset+=chunk.length;}
    let data:unknown=null;try{data=bytes?JSON.parse(new TextDecoder().decode(all)):null;}catch{if(response.ok)throw new Error('Expected a JSON API response.');}
    if(!response.ok){const message=data&&typeof data==='object'&&'message' in data&&typeof data.message==='string'?data.message:`API request failed (${response.status}).`;const retry=Number(response.headers.get('Retry-After'));throw new StudioApiError(response.status,message,response.headers.has('Retry-After')&&Number.isFinite(retry)?retry:null);}
    return data as T;
  }
  const json=<T>(path:string,body:unknown,method:'POST'|'PATCH'='POST',signal?:AbortSignal)=>request<T>(path,{method,body:JSON.stringify(body),headers:{'Content-Type':'application/json'},...(signal?{signal}:{})});
  async function trashMutation(entry:string,revision:number,kind:'trash'|'restore',signal=new AbortController().signal):Promise<Entry>{
    const initial=await json<Entry|TreeOperationResult>('entries/'+id(entry)+'/'+kind,{revision,requestId:randomId()},'POST',signal);
    const result=await finishTreeOperation(initial,operation=>json<TreeOperationResult>('tree-operations/'+id(operation)+'/advance',{},'POST',signal),signal);
    return 'treeOperation' in result?result.items[0]!:result;
  }
  return {
    request,
    treeOperations:{
      status:(operation:string,signal?:AbortSignal)=>request<TreeOperationResult>('tree-operations/'+id(operation),{...(signal?{signal}:{})}),
      advance:(operation:string,signal?:AbortSignal)=>json<TreeOperationResult>('tree-operations/'+id(operation)+'/advance',{},'POST',signal),
      cancel:(operation:string,signal?:AbortSignal)=>json<TreeOperationResult>('tree-operations/'+id(operation)+'/cancel',{},'POST',signal),
    },
    files(input:{cursor?:string|null;limit?:number;parent?:string|null;search?:string}={},signal?:AbortSignal){const query=new URLSearchParams({limit:String(Math.max(1,Math.min(120,input.limit??60)))});for(const key of ['cursor','parent','search'] as const)if(input[key])query.set(key,input[key]!);return request<EntryPage>('entries?'+query,{...(signal?{signal}:{})});},
    file(entry:string,signal?:AbortSignal){return request<EntryDetails>('entries/'+id(entry),{...(signal?{signal}:{})});},
    folderContext(folder:string,signal?:AbortSignal){return request<FolderContext>('folders/'+id(folder)+'/context',{...(signal?{signal}:{})});},
    folderAncestors(folder:string,cursor:string,limit=50,signal?:AbortSignal){return request<AncestorPage>('folders/'+id(folder)+'/ancestors?'+new URLSearchParams({cursor,limit:String(Math.max(1,Math.min(100,limit)))}),{...(signal?{signal}:{})});},
    navigationChanges(after:number,signal?:AbortSignal){if(!Number.isSafeInteger(after)||after<0)throw new Error('A valid revision is required.');return request<NavigationChangePage>('navigation/changes?'+new URLSearchParams({after:String(after)}),{...(signal?{signal}:{})});},
    move(entry:string,parent:string|null,revision:number,requestId=randomId(),signal?:AbortSignal){return json<Entry>('entries/'+id(entry),{revision,parent:parent===null?null:id(parent),requestId},'PATCH',signal);},
    undoNavigation(operation:string,signal?:AbortSignal){return json<{undone:boolean}>('navigation/operations/'+id(operation)+'/undo',{},'POST',signal);},
    createFolder(name:string,parent:string|null=null,signal?:AbortSignal){return json<Entry>('folders',{name,parent},'POST',signal);},
    rename(entry:string,name:string,revision:number,signal?:AbortSignal){return json<Entry>('entries/'+id(entry),{name,revision,requestId:randomId()},'PATCH',signal);},
    trash(entry:string,revision:number,signal?:AbortSignal){return trashMutation(entry,revision,'trash',signal);},
    restore(entry:string,revision:number,signal?:AbortSignal){return trashMutation(entry,revision,'restore',signal);},
    upload(file:Blob,name:string,key:string,parent:string|null=null,signal?:AbortSignal){const body=new FormData();body.set('file',file,name);if(parent)body.set('parent',id(parent));return request<Entry>('uploads',{method:'POST',body,headers:{'Idempotency-Key':key},...(signal?{signal}:{})});},
  };
}
