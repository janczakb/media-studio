import {AnimatePresence,motion,useReducedMotion,useIsPresent} from 'motion/react';
import {createContext,useCallback,useContext,useLayoutEffect,useMemo,useState,type ReactNode} from 'react';
import {createPortal} from 'react-dom';

type Override={content:ReactNode;width:number;target:HTMLElement;visible:boolean;closing:boolean};
const DockContext=createContext<((value:Override|null)=>void)|null>(null);

// One physical surface survives library/preview transitions. Rehome before the
// modal disappears; React retains the portal owner and the width spring.
export function PersistentActionDock({children,content,width,label,enabled=true}:{children:ReactNode;content:ReactNode;width:number;label:string;enabled?:boolean}){
 const [host]=useState(()=>{const node=document.createElement('div');node.className='fms-preview-actionbar-anchor';return node;});
 const [base,setBase]=useState<HTMLDivElement|null>(null),[override,setOverride]=useState<Override|null>(null);
 const reduced=useReducedMotion();
 const place=useCallback((target:HTMLElement|null)=>{if(!target||host.parentElement===target)return;const from=host.isConnected?host.getBoundingClientRect():null;host.getAnimations().forEach(animation=>animation.cancel());const movable=target as HTMLElement&{moveBefore?:(child:Node,before:Node|null)=>void};if(host.isConnected&&target.isConnected&&movable.moveBefore)movable.moveBefore(host,null);else target.appendChild(host);if(from&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){const to=host.getBoundingClientRect();host.animate([{transform:`translateX(-50%) translate(${from.x-to.x}px,${from.y-to.y}px)`},{transform:'translateX(-50%) translate(0px,0px)'}],{duration:220,easing:'cubic-bezier(.22,1,.36,1)'});} },[host]);
 const update=useCallback((value:Override|null)=>{place(value?.target??base);setOverride(value);},[place,base]);
 useLayoutEffect(()=>{place(override?.target??base);},[place,override,base]);
 useLayoutEffect(()=>()=>host.remove(),[host]);
 const preview=override&&!override.closing;
 const visible=enabled&&(preview?override.visible:true),mode=preview?'preview':'library';
 const transition=useMemo(()=>reduced?{duration:0}:{type:'spring' as const,visualDuration:.22,bounce:0},[reduced]);
 return <DockContext value={update}>{children}<div ref={setBase} className="fms-library-action-dock"/>{createPortal(<motion.div className="fms-preview-actionbar" role="toolbar" aria-label={label} hidden={!visible} initial={false} animate={{width:preview?override.width:width}} transition={transition}>
  <AnimatePresence initial={false}><DockContents key={mode} reduced={reduced===true}>{preview?override.content:content}</DockContents></AnimatePresence>
 </motion.div>,host)}</DockContext>;
}
export function PreviewDockOverride({children,width,visible,closing=false}:{children:ReactNode;width:number;visible:boolean;closing?:boolean}){
 const update=useContext(DockContext),[target,setTarget]=useState<HTMLDivElement|null>(null);
 useLayoutEffect(()=>{if(target)update?.({content:children,width,target,visible,closing});});
 useLayoutEffect(()=>()=>update?.(null),[update]);
 return <div ref={setTarget} className="fms-preview-dock-layer"/>;
}

function DockContents({children,reduced}:{children:ReactNode;reduced:boolean}){
 const present=useIsPresent();
 return <motion.div className="fms-preview-action-content" inert={!present} aria-hidden={!present} style={present?{position:'relative'}:{position:'absolute',left:6,top:6}} initial={{opacity:0,y:4,filter:'blur(2px)'}} animate={{opacity:1,y:0,filter:'blur(0px)'}} exit={{opacity:0,y:-4,filter:'blur(2px)',pointerEvents:'none'}} transition={{duration:reduced?0:.16}}>{children}</motion.div>;
}
