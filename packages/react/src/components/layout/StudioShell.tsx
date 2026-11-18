import {Button} from '@heroui/react';
import {useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode} from 'react';
import {Menu09Icon} from '@hugeicons/core-free-icons';
import {Icon} from '../../ui';

interface Props {
  preferenceKey:string;
  libraryScrollRef?:React.RefObject<HTMLDivElement|null>;
  scrollLibrary?:boolean;
  rail: ReactNode;
  panel: ReactNode;
  panelKey: string;
  panelLabel: string;
  panelWidth: number;
  picker: boolean;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  closeLabel: string;
  openLabel: string;
  header: (trigger: ReactNode) => ReactNode;
  children: ReactNode;
  overlays: ReactNode;
  pickerPreviewLayer?: ReactNode;
}

/** Owns geometry and disclosure. Page modules only supply content slots. */
export function StudioShell({libraryScrollRef,scrollLibrary=false,preferenceKey,rail, panel, panelKey, panelLabel, panelWidth, picker, open, onOpenChange, closeLabel, openLabel, header, children, overlays, pickerPreviewLayer}: Props) {
  const key=preferenceKey+'.subnav';
  const read=()=>{try{return JSON.parse(localStorage.getItem(key)??'{}') as {width?:number;collapsed?:boolean};}catch{return {};}};
  const [width,setWidth]=useState(()=>Math.max(180,Math.min(400,read().width??panelWidth)));
  useEffect(()=>{const saved=read();setWidth(Math.max(180,Math.min(400,saved.width??panelWidth)));},[key,panelWidth]);
  const persist=(nextWidth=width)=>{try{localStorage.setItem(key,JSON.stringify({width:nextWidth}));}catch{}};
  const id = useId();
  const shell = useRef<HTMLDivElement>(null);
  const sidebar = useRef<HTMLElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const [compact, setCompact] = useState(false);
  const hasPanel = !!panel;
  useLayoutEffect(() => {
    const node = shell.current?.closest<HTMLElement>('.fms-root') ?? shell.current;
    if (!node) return;
    const measure = () => setCompact(node.getBoundingClientRect().width <= 760);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, []);
  useLayoutEffect(() => {
    const element = sidebar.current;
    if (element) element.inert = compact && !open;
    if (compact && open) element?.querySelector<HTMLElement>('button,[href],[tabindex="0"]')?.focus();
  }, [compact, open, panelKey]);
  useEffect(() => {
    if (!hasPanel && open) onOpenChange(false);
  }, [hasPanel, open, onOpenChange]);
  const dismiss = () => {onOpenChange(false); trigger.current?.focus();};
  const disclosure = hasPanel && compact ? <Button ref={trigger} isIconOnly variant="ghost" className="fms-navigation-trigger" aria-label={openLabel} aria-controls={id} aria-expanded={open} onPress={() => onOpenChange(!open)}><Icon icon={Menu09Icon} size={28}/></Button> : null;
  const headerContent = header(disclosure);
  const style = {'--fms-panel-width': `${compact?panelWidth:width}px`, '--fms-rail-width': rail ? '48px' : '0px'} as CSSProperties;
  return <div ref={shell} style={style} data-panel={hasPanel ? panelKey : 'none'} className={`fms-shell${compact ? ' is-navigation-compact' : ''}${compact && open && hasPanel ? ' is-navigation-open' : ''}${!hasPanel ? ' is-sidebar-hidden' : ''}${!rail ? ' is-rail-hidden' : ''}${picker ? ' fms-picker' : ''}`} onKeyDown={event => {
    if (event.key === 'Escape' && compact && open && !event.defaultPrevented) {event.preventDefault(); dismiss();}
  }}>
    {rail}
    {hasPanel && <>
      <Button isIconOnly variant="ghost" className="fms-navigation-dismiss" aria-label={closeLabel} excludeFromTabOrder onPress={dismiss}/>
      <aside ref={sidebar} id={id} className="fms-sidebar" aria-label={panelLabel}>{panel}{!compact&&<div role="separator" aria-label="Navigation width" aria-orientation="vertical" aria-valuemin={180} aria-valuemax={400} aria-valuenow={width} tabIndex={0} className="fms-subnav-resize" onKeyDown={event=>{if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();const next=Math.max(180,Math.min(400,width+(event.key==='ArrowRight'?20:-20)));setWidth(next);persist(next);}}} onPointerDown={event=>{event.preventDefault();event.currentTarget.setPointerCapture(event.pointerId);}} onPointerMove={event=>{if(!event.currentTarget.hasPointerCapture(event.pointerId))return;const rect=shell.current!.getBoundingClientRect();const next=Math.max(180,Math.min(400,event.clientX-rect.left-(rail?48:0)));setWidth(next);}} onPointerUp={event=>{if(event.currentTarget.hasPointerCapture(event.pointerId))event.currentTarget.releasePointerCapture(event.pointerId);persist();}}/>}</aside>
    </>}
    <main ref={scrollLibrary?libraryScrollRef:undefined} className={`fms-main${scrollLibrary?' is-library-scroll':''}`}>
      {headerContent || (disclosure && <div className="fms-panel-access">{disclosure}</div>)}
      {children}
    </main>
    {pickerPreviewLayer}
    {overlays}
  </div>;
}
