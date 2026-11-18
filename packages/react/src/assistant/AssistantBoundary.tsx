import {defaultCharacter,normalizeCharacter} from '../characters/model';
import type {AssistantProfile,AssistantAction} from './contracts';
import {Button} from '@heroui/react';
import {BubbleChatSparkIcon} from '@hugeicons/core-free-icons';
import {createContext,lazy,Suspense,useContext,useEffect,useMemo,useState,useLayoutEffect,useCallback,type ReactNode} from 'react';
import {Icon} from '../ui';
import type {AssistantConfiguration,AssistantTransport} from './contracts';
const Surface=lazy(()=>import('./AssistantSurface'));
const AgentGlyph=lazy(async()=>({default:(await import('../characters/Character')).Character}));
const AssistantContext=createContext<{enabled:boolean;toggle:()=>void;showHistory:()=>void;historyOpen:boolean;open:boolean;close:()=>void;setTarget:(node:HTMLDivElement|null)=>void;panelWidth:number;setPanelWidth:(width:number)=>void;profile:AssistantProfile;setProfile:(profile:AssistantProfile)=>void}>({enabled:false,toggle:()=>{},showHistory:()=>{},historyOpen:false,open:false,close:()=>{},setTarget:()=>{},panelWidth:384,setPanelWidth:()=>{},profile:{revision:0,name:'Assistant',character:defaultCharacter},setProfile:()=>{}});
export function AssistantBoundary({children,configuration,transport,workspace,file,folder,portal,locale,identity,onMutation,onAction,userName}:{children:ReactNode;configuration:AssistantConfiguration|undefined;transport:AssistantTransport|undefined;workspace:string|null;file:string|null;folder:string|null;portal:HTMLDivElement;locale:string;identity:string;onMutation:()=>void;onAction:(action:AssistantAction)=>void;userName:string}) {
  const [host]=useState(()=>{const node=document.createElement('div');node.className='fms-assistant-host';return node;});
  useLayoutEffect(()=>()=>host.remove(),[host]);
  const stateKey='fms.assistant.ui.'+encodeURIComponent(identity)+'.'+(workspace??'none');
  const readWidth=()=>{try{return Math.max(320,Math.min(800,Number(localStorage.getItem('fms.assistant.'+encodeURIComponent(identity)+'.'+workspace+'.width'))||384));}catch{return 384;}};
  const [panelWidth,setPanelWidth]=useState(readWidth);
  useEffect(()=>setPanelWidth(readWidth()),[stateKey]);
  const readState=()=>{try{return JSON.parse(localStorage.getItem(stateKey)??'{}') as {open?:boolean;history?:boolean};}catch{return {};}};
  const enabled=!!(configuration?.enabled&&transport&&workspace),[open,setOpen]=useState(()=>readState().open===true),[historyOpen,setHistoryOpen]=useState(()=>readState().history===true),[started,setStarted]=useState(()=>readState().open===true||readState().history===true),[target,setTarget]=useState<HTMLDivElement|null>(null);
  useEffect(()=>{const saved=readState();setOpen(saved.open===true);setHistoryOpen(saved.history===true);setStarted(saved.open===true||saved.history===true);},[stateKey]);
  useEffect(()=>{if(!enabled)return;try{localStorage.setItem(stateKey,JSON.stringify({open,history:historyOpen}));}catch{}},[stateKey,enabled,open,historyOpen]);
  
  const [savedProfile,setSavedProfile]=useState<{key:string;profile:AssistantProfile}>();
  const profile=savedProfile?.key===stateKey?savedProfile.profile:{revision:0,name:'Assistant',character:defaultCharacter};
  const setProfile=useCallback((value:AssistantProfile)=>setSavedProfile({key:stateKey,profile:{...value,character:normalizeCharacter(value.character)}}),[stateKey]);
  useEffect(()=>{if(!enabled||!workspace||!transport?.profile)return;const controller=new AbortController();void transport.profile(workspace,controller.signal).then(value=>{if(!controller.signal.aborted)setProfile(value);}).catch(()=>{});return()=>controller.abort();},[enabled,workspace,transport,setProfile]);

  const placeHost=useCallback((node:HTMLDivElement|null)=>{
    const parent=node??portal;
    if(host.parentElement!==parent){
      const positions=[...host.querySelectorAll<HTMLElement>('.fms-assistant-messages,.fms-assistant-history-scroll,textarea')].map(el=>[el,el.scrollTop,el.scrollLeft] as const);
      const movable=parent as HTMLElement&{moveBefore?:(child:Node,before:Node|null)=>void};
      if(host.isConnected&&parent.isConnected&&movable.moveBefore)movable.moveBefore(host,null);else parent.appendChild(host);
      for(const [el,top,left] of positions){el.scrollTop=top;el.scrollLeft=left;}
      if(node){host.inert=false;host.removeAttribute('aria-hidden');}
    }
  },[host,portal]);
  // Ref detachment precedes removal of the preview dialog. Rehome now, while
  // both containers are connected, so native scroll/focus and observers survive.
  const previewTarget=useCallback((node:HTMLDivElement|null)=>{placeHost(node);setTarget(node);},[placeHost]);
  useLayoutEffect(()=>placeHost(target),[placeHost,target]);
  const value=useMemo(()=>({enabled,panelWidth,setPanelWidth,profile,setProfile,open:enabled&&open,historyOpen,close:()=>setOpen(false),toggle:()=>{setStarted(true);setOpen(v=>!v);},showHistory:()=>{setStarted(true);setHistoryOpen(v=>!v);},setTarget:previewTarget}),[enabled,open,historyOpen,previewTarget,profile,setProfile,panelWidth]);
  return <AssistantContext value={value}>{children}{enabled&&started&&transport&&configuration&&workspace&&<Suspense fallback={null}><Surface key={workspace+identity} open={open} onOpen={()=>setOpen(true)} historyOpen={historyOpen} onHistoryChange={setHistoryOpen} onClose={()=>setOpen(false)} host={host} target={target??portal} preview={!!target} workspace={workspace} file={file} folder={folder} transport={transport} configuration={configuration} locale={locale} identity={identity} onMutation={onMutation} onAction={onAction} userName={userName}/></Suspense>}</AssistantContext>;
}
export function AssistantToggle({rail=false,locale='en'}:{rail?:boolean;locale?:string}) {
  const state=useContext(AssistantContext);
  return state.enabled?<Button isIconOnly variant="ghost" size="sm" className={rail?'fms-rail-control':''} aria-label={locale==='pl'?'Rozmowy AI':'AI chats'} aria-pressed={rail?state.historyOpen:state.open} onPress={rail?state.showHistory:state.toggle}><>{rail?<Icon icon={BubbleChatSparkIcon} size={24} strokeWidth={1.5}/>:<Suspense fallback={null}><AgentGlyph config={state.profile.character??defaultCharacter} size={28} animate={false} label={state.profile.name}/></Suspense>}</></Button>:null;
}
export function AssistantPreviewSlot(){const state=useContext(AssistantContext);return state.enabled?<div ref={state.setTarget} className="fms-assistant-preview-slot"/>:null;}

export function useAssistantPreview(){return useContext(AssistantContext);}
