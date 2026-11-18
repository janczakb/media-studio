import {ext} from '../core/webextension';
import {admitJobs,jobFor,list,read,readBlob,remove,save,database} from '../core/storage';
import {httpUrl} from '../core/urls';
import type {Asset,Job,CaptureTagInput,StoredBlob,UploadPolicy,UploadSession} from '../core/types';
import {api,ApiError,workspacePath} from './api';
import {connection,preferences} from './settings';
import {downloadWithFallback,fileName} from './download';
const active=new Map<string,AbortController>();let draining=false;
export async function enqueue(assets:Asset[],connectionId:string,parent:string|null,tags:string[]=[],description='',tagRefs:CaptureTagInput[]=[]):Promise<string[]>{
 await connection(connectionId);if(assets.length<1||assets.length>300||tags.length>32||tagRefs.length>32||description.length>4000)throw new Error('Select between 1 and 300 files.');
 const jobs=assets.map(asset=>({...jobFor({...asset,name:asset.name.slice(0,160),description:asset.description.slice(0,4000)},connectionId,parent,[...new Set([...(asset.tags??[]),...tags])].slice(0,32),description),tagRefs}));await admitJobs(jobs);await ext.alarms.create('flex-capture-queue',{periodInMinutes:0.5});void drain();return jobs.map(j=>j.id);
}
export async function persistBlob(id:string,blob:Blob):Promise<void>{const db=await database();await new Promise<void>((resolve,reject)=>{const tx=db.transaction('blobs','readwrite');const store=tx.objectStore('blobs');const req=store.getAll();let failure:Error|null=null;req.onsuccess=()=>{const bytes=(req.result as StoredBlob[]).filter(v=>v.id!==id).reduce((n,v)=>n+v.blob.size,0);if(bytes+blob.size>256*1024*1024){failure=new Error('Local capture storage is full. Finish or remove queued captures.');tx.abort();return;}store.put({id,blob});};tx.oncomplete=()=>resolve();tx.onabort=()=>reject(failure??tx.error);tx.onerror=()=>reject(tx.error);});}
export async function action(id:string,operation:'pause'|'resume'|'retry'|'cancel'):Promise<void>{const j=await read<Job>('jobs',id);if(!j||['done','cancelled'].includes(j.state))return;
 active.get(id)?.abort();j.state=operation==='pause'?'paused':operation==='cancel'?'cancelled':'queued';j.error=null;j.nextAttemptAt=0;j.updatedAt=Date.now();if(operation==='retry')j.attempts=0;await save('jobs',j);
 if(operation==='cancel'){await remove('blobs',id);if(j.session){try{const c=await connection(j.connectionId);await api(c,workspacePath(c,`upload-sessions/${j.session}`),'DELETE');}catch{ /* Server TTL also cleans abandoned sessions. */ }}}void drain();}
export async function clearFinished():Promise<void>{for(const j of await list<Job>('jobs'))if(['done','cancelled'].includes(j.state)){await remove('jobs',j.id);await remove('blobs',j.id);}}
async function run(job:Job):Promise<void>{
 const controller=new AbortController();active.set(job.id,controller);const signal=controller.signal;let last=0;
 const update=async(patch:Partial<Job>)=>{signal.throwIfAborted();Object.assign(job,patch,{updatedAt:Date.now()});await save('jobs',job);};
 try{
 const c=await connection(job.connectionId);const prefs=await preferences();const policy=await api<UploadPolicy>(c,workspacePath(c,'upload-policy'),'GET',undefined,signal);
 let blob=await readBlob(job.id)??(job.asset.localBlobId?await readBlob(job.asset.localBlobId):undefined);
 if(!job.entry){
 if(!blob){await update({state:'downloading',loaded:0});blob=await downloadWithFallback(job.asset,Math.min(prefs.maxDownloadBytes,policy.enabled?policy.maxBytes:policy.ordinaryMaxBytes),signal,(loaded,total)=>{if(Date.now()-last>500){last=Date.now();void update({loaded,size:total}).catch(()=>{});}},prefs.browserSession);await persistBlob(job.id,blob);}
 signal.throwIfAborted();await update({state:'uploading',loaded:0,size:blob.size});
 const name=fileName(job.asset,blob);let entry:{id:string};
 if(policy.enabled&&blob.size>Math.min(policy.chunkBytes,policy.ordinaryMaxBytes)){
  let session=job.session?await api<UploadSession>(c,workspacePath(c,`upload-sessions/${job.session}`),'GET',undefined,signal):await api<UploadSession>(c,workspacePath(c,'upload-sessions'),'POST',{name,size:blob.size,mime:blob.type,parent:job.parent,key:job.id,direct:false},signal);
  await update({session:session.id});if(session.entry)entry=session.entry;
  else {if(!Number.isSafeInteger(session.chunkBytes)||session.chunkBytes<65536||session.chunkBytes>32*1024*1024)throw new Error('Invalid upload part size.');
   const parts=Math.ceil(blob.size/session.chunkBytes),accepted=new Set(session.acceptedParts);for(let index=0;index<parts;index++){signal.throwIfAborted();if(!accepted.has(index))await api(c,workspacePath(c,`upload-sessions/${session.id}/parts/${index}`),'PUT',blob.slice(index*session.chunkBytes,(index+1)*session.chunkBytes),signal);await update({loaded:Math.min(blob.size,(index+1)*session.chunkBytes)});}
   entry=await api(c,workspacePath(c,`upload-sessions/${session.id}/complete`),'POST',{},signal);
  }
 }else {if(blob.size>policy.ordinaryMaxBytes)throw new Error('Enable chunk uploads in Studio for this file.');const body=new FormData();body.append('file',blob,name);body.append('key',job.id);if(job.parent)body.append('parent',job.parent);entry=await api(c,workspacePath(c,'uploads'),'POST',body,signal);}
 await update({entry:entry.id,loaded:blob.size});
 }
 const source=httpUrl(job.asset.pageUrl);if(source)await api(c,workspacePath(c,`captures/${job.entry}`),'POST',{key:job.id,sourceUrl:source,description:job.description||job.asset.description,tags:job.tags,tagRefs:job.tagRefs??[]},signal);
 await update({state:'done',error:null});await remove('blobs',job.id);
 }catch(error){
  const current=await read<Job>('jobs',job.id);if(!current||current.state==='paused'||current.state==='cancelled')return;
  const retry=!(error instanceof ApiError)||error.status===429||error.status>=500;const attempts=job.attempts+1;
  await save('jobs',{...job,state:retry&&attempts<4?'queued':'error',error:error instanceof Error?error.message:'Upload failed.',attempts,updatedAt:Date.now(),nextAttemptAt:Date.now()+Math.min(120000,5000*2**attempts)} satisfies Job);
 }finally{active.delete(job.id);}
}
export async function drain():Promise<void>{if(draining)return;draining=true;try{
 const prefs=await preferences();const jobs=await list<Job>('jobs');for(const j of jobs)if(!active.has(j.id)&&['downloading','uploading'].includes(j.state)&&Date.now()-j.updatedAt>35000){j.state='queued';await save('jobs',j);}
 const available=Math.max(0,Math.min(3,prefs.concurrency)-active.size);const ready=(await list<Job>('jobs')).filter(j=>j.state==='queued'&&j.nextAttemptAt<=Date.now()&&!active.has(j.id)).sort((a,b)=>a.createdAt-b.createdAt).slice(0,available);
 for(const j of ready)void run(j).finally(()=>{void drain();});
 const unfinished=jobs.filter(j=>!['done','cancelled'].includes(j.state)).length;await ext.action.setBadgeText({text:unfinished?String(unfinished):''});await ext.action.setBadgeBackgroundColor({color:'#2563eb'});
 }finally{draining=false;}}
