import type {Job,JobState} from './types';
export function captureStatus(job:Job|undefined,connectionId:string,pageUrl:string):{state:JobState;entry:string|null}{
 if(!job||job.connectionId!==connectionId||new URL(job.asset.pageUrl).origin!==new URL(pageUrl).origin)throw new Error('Capture unavailable.');
 return {state:job.state==='done'&&!job.entry?'error':job.state,entry:job.state==='done'?job.entry:null};
}
export interface CaptureSummary {total:number;done:number;failed:number;pending:number;}
export function summarizeCaptures(states:{state:JobState;entry:string|null}[]):CaptureSummary{
 const result:CaptureSummary={total:states.length,done:0,failed:0,pending:0};
 for(const item of states){if(item.state==='done'&&item.entry)result.done++;else if(['done','error','cancelled','paused'].includes(item.state))result.failed++;else result.pending++;}
 return result;
}
