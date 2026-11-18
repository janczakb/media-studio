import type {Connection} from '../core/types';
export class ApiError extends Error {constructor(readonly status:number,message:string){super(message);}}
export async function api<T>(c:Connection,path:string,method='GET',body?:BodyInit|Record<string,unknown>,signal?:AbortSignal):Promise<T>{
 if(!path.startsWith('/')||path.includes('..'))throw new Error('Invalid API operation.');
 const h=new Headers({Accept:'application/json',Authorization:`Bearer ${c.token}`});const isJson=body!==undefined&&!(body instanceof FormData)&&!(body instanceof Blob)&&typeof body!=='string';if(isJson)h.set('Content-Type','application/json');
 const response=await fetch(c.apiBase+path,{method,headers:h,credentials:'omit',cache:'no-store',redirect:'error',signal:signal?AbortSignal.any([signal,AbortSignal.timeout(25000)]):AbortSignal.timeout(25000),...(body===undefined?{}:{body:isJson?JSON.stringify(body):body as BodyInit})});
 const text=await response.text();if(text.length>2_000_000)throw new Error('Server response exceeded the limit.');let data:unknown;try{data=JSON.parse(text);}catch{throw new ApiError(response.status,'Studio returned an invalid response.');}
 if(!response.ok)throw new ApiError(response.status,typeof data==='object'&&data&&'message'in data?String(data.message):`Studio error (${response.status}).`);return data as T;
}
export const workspacePath=(c:Connection,suffix:string)=>`/workspaces/${encodeURIComponent(c.workspace.id)}/${suffix}`;
