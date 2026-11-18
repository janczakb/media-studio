import {Button,Spinner} from '@heroui/react';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport,SourceImportPage,SourceImportRun} from '../../contracts';
import {errorText,type Messages} from '../../ui';

export function SourceImportsPanel({workspace,transport,t,onImported}:{onImported:(workspace:string)=>void;workspace:string;transport:StudioTransport;t:Messages}){
 const [page,setPage]=useState<SourceImportPage|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const active=useRef<AbortController|null>(null),activeRun=useRef<SourceImportRun|null>(null);
 const replace=(run:SourceImportRun)=>setPage(previous=>previous?{...previous,items:[run,...previous.items.filter(item=>item.id!==run.id)].slice(0,30)}:previous);
 const refresh=async(signal:AbortSignal)=>{const next=await transport.sourceImports!(workspace,signal);if(!signal.aborted)setPage(next);};
 useEffect(()=>{const controller=new AbortController();active.current=controller;setPage(null);setError('');void refresh(controller.signal).catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));});return()=>controller.abort();},[workspace,transport]);
 useEffect(()=>()=>active.current?.abort(),[]);
 const perform=(action:(signal:AbortSignal)=>Promise<void>)=>{
  if(busy)return;active.current?.abort();const controller=new AbortController();active.current=controller;setBusy(true);setError('');
  void action(controller.signal).catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{if(!controller.signal.aborted)setBusy(false);});
 };
 const pump=async(initial:SourceImportRun,signal:AbortSignal)=>{
  if(signal.aborted)return;
  let run=initial;activeRun.current=run;replace(run);
  while(!signal.aborted&&run.state==='ready'){
   run=await transport.stepSourceImport!(workspace,run.id,run.revision,signal);
   if(!signal.aborted){activeRun.current=run;replace(run);}
  }
  if(!signal.aborted&&run.state==='completed')onImported(workspace);
 };
 const continueRun=(run:SourceImportRun)=>perform(async signal=>{
  const next=run.state==='ready'?run:await transport.controlSourceImport!(workspace,run.id,run.revision,'resume',signal);
  await pump(next,signal);
 });
 return <>
 <p className="fms-settings-note">{t.sourceImportsHint}</p>
 {error&&<p role="alert" className="fms-error">{error}</p>}
 {!page&&!error&&<Spinner aria-label={t.loading}/>}
 {page?.sources.length===0&&<p>{t.sourceImportEmpty}</p>}
 <div className="fms-settings-group">{page?.sources.map(source=><div className="fms-settings-row" key={source.key}>
  <div><strong>{source.label}</strong>{!source.canImport&&<p>{t.sourceImportPrepare}</p>}</div>
  <Button size="sm" variant="secondary" isDisabled={busy||!source.canImport||!transport.startSourceImport||!transport.stepSourceImport} onPress={()=>perform(async signal=>{await pump(await transport.startSourceImport!(workspace,source.key,signal),signal);})}>{t.sourceImportStart}</Button>
 </div>)}</div>
 {page?.items.map(run=><div className="fms-settings-group" key={run.id}>
  <div className="fms-settings-row"><div><strong>{page.sources.find(source=>source.key===run.mount)?.label??run.mount}</strong><p>{t[`sourceImportState_${run.state}`]} · {t.sourceImportProcessed}: {run.processed}</p></div><span>{run.progress}%</span></div>
  {!['completed','cancelled'].includes(run.state)&&<div className="fms-settings-row">
   <Button size="sm" variant="secondary" isDisabled={busy||!run.canAdvance||!transport.controlSourceImport||!transport.stepSourceImport} onPress={()=>continueRun(run)}>{t.sourceImportContinue}</Button>
   <Button size="sm" variant="ghost" isDisabled={busy||!transport.controlSourceImport} onPress={()=>perform(async signal=>{replace(await transport.controlSourceImport!(workspace,run.id,run.revision,'cancel',signal));})}>{t.sourceImportCancel}</Button>
  </div>}
 </div>)}
 {busy&&<Button size="sm" variant="secondary" onPress={()=>{active.current?.abort();const run=activeRun.current;const controller=new AbortController();active.current=controller;setBusy(true);void (async()=>{if(run&&transport.controlSourceImport){const current=await transport.sourceImports!(workspace,controller.signal);const latest=current.items.find(item=>item.id===run.id);if(latest&&!['completed','cancelled'].includes(latest.state))replace(await transport.controlSourceImport(workspace,latest.id,latest.revision,'pause',controller.signal));}await refresh(controller.signal);})().catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{if(!controller.signal.aborted)setBusy(false);});}}>{t.sourceImportPause}</Button>}
 <Button size="sm" variant="ghost" isDisabled={busy} onPress={()=>perform(refresh)}>{t.sourceImportRefresh}</Button>
 </>;
}
