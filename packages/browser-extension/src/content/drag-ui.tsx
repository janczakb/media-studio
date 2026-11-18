import {CaptureTagField,type CaptureTag} from '../ui/CaptureTagField';
import {CaptureStack} from '../ui/CaptureStack';
import {showSaveNotice} from './save-notice';
import {createRoot} from 'react-dom/client';
import {useEffect,useLayoutEffect,useRef,useState} from 'react';
import {Button,Input,Spinner,SearchField} from '@heroui/react';
import {ext,send} from '../core/webextension';
import type {Asset,Preferences} from '../core/types';
import type {CaptureFolder} from '../core/folder-window';
import {FolderPicker} from '../ui/FolderPicker';
import {Choice,words} from '../ui/shared';
import {placement,type Point} from './drag-geometry';
import type {DragWorld} from './drag-types';
interface Props {assets:Asset[];start:Point;current:Point;preferences:Preferences;close:()=>void;portalContainer:HTMLElement;}
interface Context {connectionId:string|null;workspace:string|null;canCreate?:boolean;workspacesEnabled:boolean;connections:{id:string;name:string}[];}
function FolderIllustration() {
 return <img className="fc-folder-illustration" src={ext.runtime.getURL('capture-folder-outline.svg')} alt="" aria-hidden="true" draggable={false}/>;
}

function Panel({assets,start,current,preferences:prefs,close,portalContainer}:Props){
 const t=words(prefs.locale);
 const asset=assets[0]!;
 const multiple=assets.length>1;
 const [context,setContext]=useState<Context|null>(null);
 const [error,setError]=useState('');
 const [edit,setEdit]=useState(false);
 const [parent,setParent]=useState<{id:string;name:string}|null>(null);
 const [name,setName]=useState(asset.name);
 const [tags,setTags]=useState<CaptureTag[]>([]);
 const [folderSearch,setFolderSearch]=useState('');
 const [busy,setBusy]=useState(false);
 const [selectedConnection,setSelectedConnection]=useState('');
 const [retry,setRetry]=useState(0);
 const panel=useRef<HTMLElement>(null);
 const [size,setSize]=useState({width:Math.min(560,innerWidth-20),height:360});
 useLayoutEffect(()=>{
  const element=panel.current;if(!element)return;
  const observer=new ResizeObserver(()=>{const width=element.offsetWidth,height=element.offsetHeight;setSize(old=>old.width===width&&old.height===height?old:{width,height});});
  observer.observe(element);return()=>observer.disconnect();
 },[edit,prefs.quickMode]);
 useEffect(()=>{
  let alive=true;setContext(null);setError('');
  void send<Context>('drag.context',{connectionId:selectedConnection}).then(value=>{if(alive)setContext(value);},()=>{if(alive)setError(t.notReady);});
  return()=>{alive=false;};
 },[selectedConnection,retry,t.notReady]);
 const position=placement(start,current,size,{width:innerWidth,height:innerHeight});
 const ready=!!context?.connectionId&&!busy;
 const chooseConnection=(id:string)=>{if(busy)return;setContext(null);setParent(null);setTags([]);setFolderSearch('');setSelectedConnection(id);};
 const chooseFolder=(folder:{id:string;name:string}|null)=>{if(!ready)return;setParent(folder);(globalThis as DragWorld).flexCaptureDrag!.retain=true;setEdit(true);};
 const save=async(id:string|null)=>{
  if(!ready||!context?.connectionId)return;
  setBusy(true);setError('');
  try{const result=await send<string[]|{confirmation:true}>('drag.save',{assets:assets.map(item=>multiple?item:{...item,name}),connectionId:context.connectionId,parent:id,tagRefs:tags.map(tag=>tag.id?{id:tag.id}:{name:tag.name})});if(Array.isArray(result)&&result[0])showSaveNotice(assets.map(item=>multiple?item:{...item,name}),result,context.connectionId,context.workspace??'',prefs);close();}
  catch(e){setError(e instanceof Error?e.message:t.failed);}finally{setBusy(false);}
 };
 const drop=(folder:CaptureFolder|null,choose=false)=>{if(!ready)return;if(choose||prefs.askDestination)chooseFolder(folder);else void save(folder?.id??null);};
 const target=(folder:CaptureFolder|null,choose=false)=>({
  onDragOver:(event:React.DragEvent)=>{if(!ready)return;event.preventDefault();event.currentTarget.classList.add('is-over');},
  onDragLeave:(event:React.DragEvent)=>{if(!event.currentTarget.contains(event.relatedTarget as Node))event.currentTarget.classList.remove('is-over');},
  onDrop:(event:React.DragEvent)=>{event.preventDefault();event.stopPropagation();event.currentTarget.classList.remove('is-over');drop(folder,choose);},
 });
 const workspace=context?.workspacesEnabled?<Choice label={t.workspace} value={context.connectionId??''} items={context.connections} onChange={chooseConnection} portalContainer={portalContainer}/>:null;
 if(prefs.quickMode==='dock'&&!edit)return <section className="fc-bottom" {...target(null)}><FolderIllustration/><span>{t.dropHere}</span></section>;
 return <section ref={panel} aria-label={t.saveToStudio} className={'fc-drag-panel'+(edit?' is-confirming':'')} style={{left:position.x,top:position.y}}>
  <div className="fc-drag-left" {...target(null)}>
   {edit?<div className="fc-dropped-preview"><CaptureStack assets={assets}/>{multiple?<strong>{t.fileCount.replace('{count}',String(assets.length))}</strong>:<Input className="fc-capture-name" aria-label={t.name} variant="secondary" value={name} disabled={busy} onChange={event=>setName(event.target.value)}/>}<span>{parent?.name??t.root}</span></div>:<button type="button" className="fc-drop-target" disabled={!ready} onClick={()=>chooseFolder(null)}><FolderIllustration/><span>{t.dropHere}</span><small>{multiple?t.fileCount.replace('{count}',String(assets.length)):asset.name}</small></button>}
  </div>
  <div className="fc-drag-right">
   <div className="fc-drag-destination">{workspace??<span>{context?.workspace??t.saveToStudio}</span>}</div>
   <>
    <div className="fc-inline-folder-search"><SearchField aria-label={t.searchFolders} value={folderSearch} onChange={setFolderSearch} isDisabled={!ready} variant="secondary"><SearchField.Group><SearchField.SearchIcon/><SearchField.Input placeholder={t.searchFolders}/><SearchField.ClearButton/></SearchField.Group></SearchField></div>
    {context?.connectionId?<FolderPicker key={context.connectionId} compact inPage canCreate={false} searchValue={folderSearch} onSearchChange={setFolderSearch} locale={prefs.locale} connectionId={context.connectionId} value={parent} onChange={chooseFolder} onDropFolder={folder=>drop(folder)}/>:<div className="fc-recent-folders">{!error?<Spinner size="sm" aria-label={t.loading}/>:<p>{error}</p>}</div>}
   </>
   {edit&&context?.connectionId&&<div className="fc-inline-tag-options"><CaptureTagField connectionId={context.connectionId} locale={prefs.locale} value={tags} onChange={setTags} disabled={busy} portal={portalContainer}/></div>}
   {error&&<p className="fc-confirm-error" role="alert">{error}</p>}
   {edit&&<footer className="fc-inline-save-footer"><Button size="sm" variant="secondary" isDisabled={busy} onClickCapture={close}>{t.cancel}</Button><Button size="sm" isPending={busy} isDisabled={!ready||(!multiple&&!name.trim())} onClickCapture={()=>void save(parent?.id??null)}>{t.save}</Button></footer>}
  </div>
 </section>;
}

const world=globalThis as DragWorld;
if(!world.flexCaptureDrag){
 let dialog:HTMLDialogElement|null=null;let wrapper:HTMLDivElement|null=null;let react:ReturnType<typeof createRoot>|null=null;
 const close=()=>{if(!dialog)return;world.flexCaptureDrag!.retain=false;const old=dialog,outer=wrapper,root=react;dialog=null;react=null;wrapper=null;setTimeout(()=>{root?.unmount();old.close();outer?.remove();},50);};
 world.flexCaptureDrag={retain:false,close,open(value,start,current,preferences){const assets=[...new Map((Array.isArray(value)?value:[value]).slice(0,300).map(item=>[item.url,item])).values()];if(!assets.length)return;close();const outer=document.createElement('div');outer.dataset.flexCapture='true';outer.style.cssText='position:fixed;inset:0;pointer-events:none;z-index:2147483647';const shadow=outer.attachShadow({mode:'closed'});const css=document.createElement('link');css.rel='stylesheet';css.href=ext.runtime.getURL('ui.css');const host=document.createElement('dialog');host.className='fc-dialog';const slot=document.createElement('div');slot.className='fc-root '+(preferences.theme==='dark'||preferences.theme==='system'&&matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');host.append(slot);shadow.append(css,host);document.documentElement.append(outer);dialog=host;wrapper=outer;const mount=()=>{if(dialog!==host)return;host.showModal();react=createRoot(slot);react.render(<Panel assets={assets} start={start} current={current} preferences={preferences} close={close} portalContainer={slot}/>);};css.addEventListener('load',mount,{once:true});css.addEventListener('error',close,{once:true});host.addEventListener('cancel',close,{once:true});host.addEventListener('click',e=>{if(e.target===host)close();});}
 };
}
