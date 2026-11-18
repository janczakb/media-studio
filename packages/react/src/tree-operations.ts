import type {Entry} from './contracts';

export interface TreeOperation {
  id:string; state:'preparing'|'completed'|'failed'|'cancelled'; operation:'trash'|'restore';
  discovered:number; examined:number; error:string|null;
}
export interface TreeOperationResult {treeOperation:TreeOperation;items:Entry[]}
export interface TreeOperationTransport {
  status(workspace:string,id:string,signal:AbortSignal):Promise<TreeOperationResult>;
  advance(workspace:string,id:string,signal:AbortSignal):Promise<TreeOperationResult>;
  cancel(workspace:string,id:string,signal:AbortSignal):Promise<TreeOperationResult>;
}
/** Aborting this wait leaves the durable operation resumable; cancellation is explicit. */
export async function finishTreeOperation<T>(value:T|TreeOperationResult, advance:(id:string)=>Promise<TreeOperationResult>, signal:AbortSignal, notify?:(operation:TreeOperation)=>void):Promise<T|TreeOperationResult> {
  if(!value || typeof value!=='object' || !('treeOperation' in value))return value;
  let result=value as TreeOperationResult;
  while(true){
    signal.throwIfAborted();
    notify?.(result.treeOperation);
    if(result.treeOperation.state==='completed')return result;
    if(result.treeOperation.state!=='preparing')throw new Error(result.treeOperation.error??'The folder operation was cancelled.');
    // Yield to painting and input between bounded server steps. No idle status polling.
    await new Promise<void>((resolve,reject)=>{
      const stop=()=>{clearTimeout(timer);reject(signal.reason);};
      const timer=setTimeout(()=>{signal.removeEventListener('abort',stop);resolve();},50);
      signal.addEventListener('abort',stop,{once:true});
    });
    result=await advance(result.treeOperation.id);
  }
}
