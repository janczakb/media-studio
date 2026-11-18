import {animate} from 'motion';
import {dockSurface,dockSpring} from './dockSurface';
import {Button,Tooltip} from '@heroui/react';
import {MoveUpRightIcon,CircleIcon,MousePointerIcon,Edit03Icon,EraserIcon,LinerIcon,SquareIcon,TypeIcon,Comment03Icon} from '@hugeicons/core-free-icons';
import {AnimatePresence,motion,useMotionValue,useTransform,useReducedMotion} from 'motion/react';
import {useCallback,useEffect,useLayoutEffect,useId,useRef,useState} from 'react';
import {Icon,type Messages} from '../../ui';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {usePopoverDismiss} from '../../usePopoverDismiss';
import {SlidingSelection} from '../SlidingSelection';
const shapes=[['rectangle',SquareIcon,5],['ellipse',CircleIcon,6],['arrow',MoveUpRightIcon,3],['line',LinerIcon,7]] as const;
export type DrawingTool='select'|'pen'|'erase'|'arrow'|'text'|'rectangle'|'ellipse'|'line'|'comment';
type Shape=typeof shapes[number][0];
const isShape=(value:string):value is Shape=>shapes.some(([id])=>id===value);
export function DrawingToolDock({value,onChange,busy,canComment,showComments,t,portal}:{value:DrawingTool;onChange:(tool:DrawingTool)=>void;busy:boolean;canComment:boolean;showComments:boolean;t:Messages;portal:HTMLDivElement}){
 const {tr}=useWorkspaceMessages(),reduce=useReducedMotion();const root=useRef<HTMLDivElement>(null),group=useRef<HTMLButtonElement>(null),tray=useRef<HTMLDivElement>(null),keyboardOpen=useRef(false);
 const [open,setOpen]=useState(false),[visited,setVisited]=useState(false),[length,setLength]=useState(showComments?272:228),[lastShape,setLastShape]=useState<Shape>('rectangle');const id=useId(),close=useCallback(()=>setOpen(false),[]);usePopoverDismiss(portal,open,close,id);
 const selected=isShape(value)?value:lastShape;const shape=shapes.find(([id])=>id===selected)!;const label=t.drawingTools.split('|')[shape[2]]!;
 useEffect(()=>{if(isShape(value))setLastShape(value);else setOpen(false);},[value]);
 useEffect(()=>{if(open&&keyboardOpen.current&&tray.current){keyboardOpen.current=false;tray.current?.querySelector<HTMLButtonElement>(`[data-shape="${selected}"]`)?.focus();}},[open,selected,visited]);
 const depth=useMotionValue(0),center=useMotionValue(158),breadth=useMotionValue(40),dockLength=useMotionValue(length);
 const outline=useTransform(()=>dockSurface(dockLength.get(),depth.get(),center.get(),breadth.get()));
 const trayOpacity=useTransform(depth,d=>Math.max(0,Math.min(1,(d/48-.45)/.4)));
 const trayY=useTransform(()=>Math.min(dockLength.get()-30-breadth.get()/2,Math.max(30+breadth.get()/2,center.get()))-84);
 useLayoutEffect(()=>{const element=root.current;if(!element)return;const measure=()=>{setLength(element.offsetHeight);dockLength.set(element.offsetHeight);};measure();const observer=new ResizeObserver(measure);observer.observe(element);return()=>observer.disconnect();},[dockLength]);
 useEffect(()=>{
  if(open){setVisited(true);const position=(group.current?.offsetTop??138)+(group.current?.offsetHeight??40)/2;if(reduce){center.jump(position);breadth.jump(168);depth.jump(48);return;}if(depth.get()<1){center.jump(position);breadth.jump(40);}const animations=[animate(depth,48,dockSpring(.42)),animate(center,position,dockSpring(.42)),animate(breadth,168,dockSpring(.42,.08))];return()=>animations.forEach(animation=>animation.stop());}
  if(reduce){depth.jump(0);return;}const animation=animate(depth,0,dockSpring(.3));return()=>animation.stop();
 },[open,reduce,depth,center,breadth,length]);
 const choose=(tool:DrawingTool)=>{onChange(tool);if(!isShape(tool))setOpen(false);};
 return <div ref={root} className="fms-drawing-toolbar fms-drawing-tools-main" role="toolbar" aria-orientation="vertical" aria-label={t.drawOnImage}>
 <svg className="fms-drawing-dock-surface" width="52" height={length} aria-hidden="true"><g transform="translate(52 0) rotate(90)"><motion.path d={outline}/></g></svg>
 <SlidingSelection host={root} selector='button[data-dock-main][aria-pressed="true"]' selection={value} className="fms-drawing-selection-tint"/>
 {([['select',MousePointerIcon,0],['pen',Edit03Icon,1],['erase',EraserIcon,2]] as const).map(([tool,icon,index])=><Tooltip key={tool} delay={300}><Button data-dock-main isIconOnly size="sm" variant="ghost" aria-label={t.drawingTools.split('|')[index]!} aria-pressed={value===tool} isDisabled={busy} onPress={()=>choose(tool)}><Icon icon={icon} size={18} strokeWidth={1.6}/></Button><Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{t.drawingTools.split('|')[index]!}</Tooltip.Content></Tooltip>)}
 <Tooltip delay={300} isDisabled={open}><Button ref={group} data-dock-main data-fms-popover={id} className="fms-drawing-shapes-trigger" isIconOnly size="sm" variant="ghost" aria-label={`${tr('drawingShapes')}: ${label}`} aria-pressed={isShape(value)} aria-expanded={open} aria-haspopup="true" {...(open?{'aria-controls':id+'-tray'}:{})} isDisabled={busy} onPress={()=>{onChange(selected);setOpen(v=>!v);}} onKeyDown={event=>{if(event.key==='ArrowRight'){event.preventDefault();event.stopPropagation();keyboardOpen.current=true;onChange(selected);setOpen(true);}else if(event.key==='Escape'&&open){event.preventDefault();event.stopPropagation();setOpen(false);}}}><span className="fms-drawing-shape-glyph"><AnimatePresence initial={false} mode="sync"><motion.span key={selected} initial={reduce?false:{opacity:0,scale:.9,filter:'blur(2px)'}} animate={{opacity:1,scale:1,filter:'blur(0px)'}} exit={{opacity:0}} transition={{duration:reduce?0:.14}}><Icon icon={shape[1]} size={18} strokeWidth={1.6} preserveOriginalStroke={selected==='arrow'}/></motion.span></AnimatePresence></span><span className="fms-drawing-shapes-caret" aria-hidden="true"/></Button><Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{tr('drawingShapes')}: {label}</Tooltip.Content></Tooltip>
 <Tooltip delay={300}><Button data-dock-main isIconOnly size="sm" variant="ghost" aria-label={t.drawingTools.split('|')[4]!} aria-pressed={value==='text'} isDisabled={busy} onPress={()=>choose('text')}><Icon icon={TypeIcon} size={18} strokeWidth={1.6}/></Button><Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{t.drawingTools.split('|')[4]!}</Tooltip.Content></Tooltip>
 {showComments&&<Tooltip delay={300}><Button data-dock-main isIconOnly size="sm" variant="ghost" aria-label={t.commentAdd} aria-pressed={value==='comment'} isDisabled={busy||!canComment} onPress={()=>choose('comment')}><Icon icon={Comment03Icon} size={18} strokeWidth={1.6}/></Button><Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{t.commentAdd}</Tooltip.Content></Tooltip>}
 {visited&&<ShapeTray tray={tray} id={id} open={open} opacity={trayOpacity} y={trayY} label={tr('drawingShapes')} selected={selected} busy={busy} t={t} portal={portal} onChoose={shape=>{setLastShape(shape);onChange(shape);setOpen(false);group.current?.focus();}} onClose={()=>{setOpen(false);group.current?.focus();}}/>}
 </div>;
}
function ShapeTray({tray,id,open,opacity,y,label,selected,busy,t,portal,onChoose,onClose}:{open:boolean;opacity:import('motion').MotionValue<number>;y:import('motion').MotionValue<number>;tray:React.RefObject<HTMLDivElement|null>;id:string;label:string;selected:Shape;busy:boolean;t:Messages;portal:HTMLDivElement;onChoose:(shape:Shape)=>void;onClose:()=>void}){
 const reduce=useReducedMotion();
 return <motion.div ref={tray} id={id+'-tray'} data-fms-popover={id} className="fms-drawing-shape-tray" role="toolbar" aria-label={label} aria-orientation="vertical" aria-hidden={!open} inert={!open} style={{opacity,y,pointerEvents:open?'auto':'none'}} onKeyDown={event=>{event.stopPropagation();if(event.key==='Escape'){event.preventDefault();onClose();return;}const buttons=Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>('button'));const index=buttons.indexOf(event.target as HTMLButtonElement);const next=event.key==='Home'?0:event.key==='End'?buttons.length-1:['ArrowRight','ArrowDown'].includes(event.key)?(index+1)%buttons.length:['ArrowLeft','ArrowUp'].includes(event.key)?(index-1+buttons.length)%buttons.length:-1;if(next>=0){event.preventDefault();buttons[next]?.focus();}}}>
 {shapes.map(([shape,icon,index],i)=><motion.span key={shape} initial={false} animate={open?{opacity:1,x:0,scale:1}:{opacity:0,x:6,scale:.92}} transition={reduce?{duration:0}:open?{type:'spring',visualDuration:.26,bounce:0,delay:.08+i*.035}:{duration:.16,ease:[.22,1,.36,1]}}><Tooltip delay={300}><Button data-shape={shape} isIconOnly size="sm" variant="ghost" excludeFromTabOrder={selected!==shape} aria-label={t.drawingTools.split('|')[index]!} aria-pressed={selected===shape} isDisabled={busy} onPress={()=>onChoose(shape)}><Icon icon={icon} size={18} strokeWidth={1.6} preserveOriginalStroke={shape==='arrow'}/></Button><Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{t.drawingTools.split('|')[index]!}</Tooltip.Content></Tooltip></motion.span>)}
 </motion.div>;
}
