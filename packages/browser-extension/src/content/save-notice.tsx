import {CaptureStack} from '../ui/CaptureStack';
import type {CaptureSummary} from '../core/capture-status';
import {createRoot} from 'react-dom/client';
import {useEffect,useState} from 'react';
import {Button,Spinner} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {Cancel01Icon} from '@hugeicons/core-free-icons';
import {ext,send} from '../core/webextension';
import type {Asset,Preferences} from '../core/types';
import {words} from '../ui/shared';

let dispose:(()=>void)|undefined;
export function showSaveNotice(assets:Asset[],jobIds:string[],connectionId:string,workspace:string,prefs:Preferences){
 dispose?.();
 const outer=document.createElement('div');outer.dataset.flexCapture='true';
 const shadow=outer.attachShadow({mode:'closed'});
 const css=document.createElement('link');css.rel='stylesheet';css.href=ext.runtime.getURL('ui.css');
 const host=document.createElement('div');host.className='fc-root '+(prefs.theme==='dark'||prefs.theme==='system'&&matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
 host.setAttribute('popover','manual');host.style.cssText='position:fixed;inset:16px 16px auto auto;margin:0;padding:0;border:0;background:transparent;overflow:visible';
 shadow.append(css,host);document.documentElement.append(outer);
 const root=createRoot(host);let removed=false;
 const close=()=>{if(removed)return;removed=true;window.removeEventListener('pagehide',close);root.unmount();outer.remove();if(dispose===close)dispose=undefined;};
 dispose=close;window.addEventListener('pagehide',close,{once:true});
 css.onload=()=>{if(removed)return;host.showPopover();root.render(<Notice {...{assets,jobIds,connectionId,workspace,prefs,close}}/>);};css.onerror=close;
}
function Notice({assets,jobIds,connectionId,workspace,prefs,close}:{assets:Asset[];jobIds:string[];connectionId:string;workspace:string;prefs:Preferences;close:()=>void}){
 const t=words(prefs.locale);const [state,setState]=useState<CaptureSummary>({total:jobIds.length,done:0,failed:0,pending:jobIds.length});
 const multiple=assets.length>1;const asset=assets[0]!;
 useEffect(()=>{
  let alive=true,timer:ReturnType<typeof setTimeout>|undefined;const started=Date.now();
  const poll=async()=>{
   try{const result=await send<CaptureSummary>('drag.status',{jobIds,connectionId});if(!alive)return;
    setState(result);
    if(result.done===result.total&&result.total>0){timer=setTimeout(close,5000);return;}
    if(result.pending===0)return;
   }catch{if(!alive)return;setState(current=>({...current,failed:current.total-current.done,pending:0}));return;}
   if(Date.now()-started<30*60*1000)timer=setTimeout(()=>void poll(),document.hidden?5000:1000);else setState(current=>({...current,failed:current.total-current.done,pending:0}));
  };void poll();return()=>{alive=false;clearTimeout(timer);};
 },[jobIds,connectionId,close]);
 const done=state.done===state.total;const stopped=state.pending===0&&state.failed>0;
 const title=done?(multiple?t.savedCount.replace('{done}',String(state.done)).replace('{total}',String(state.total)):t.saved):stopped?t.partialSave.replace('{done}',String(state.done)).replace('{failed}',String(state.failed)):(multiple?t.savingCount.replace('{count}',String(state.total)):t.saving);
 return <div className="fc-save-notice" role="status" aria-live="polite">
  <span className="fc-save-notice-preview"><CaptureStack assets={assets} small/>{!done&&!stopped&&<span className="fc-save-notice-progress"><Spinner size="sm"/></span>}</span>
  <div><strong>{title}</strong><span title={workspace?`${asset.name} · ${workspace}`:asset.name}>{multiple?t.fileCount.replace('{count}',String(assets.length)):asset.name}</span></div>
  <Button isIconOnly size="sm" variant="ghost" aria-label={t.dismiss} onPress={close}><HugeiconsIcon icon={Cancel01Icon} size={16}/></Button>
 </div>;
}
