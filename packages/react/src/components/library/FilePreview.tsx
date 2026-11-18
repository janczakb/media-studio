import {createPortal} from 'react-dom';
import type {FilePreviewHandle} from '../../preview-contract';
import {PreviewFileName} from './PreviewFileName';
import {createImageViewport} from '../../image-preview/viewport';
import type {ImagePreviewSettings} from '../../image-preview/contracts';
import {AssistantPreviewSlot,useAssistantPreview} from '../../assistant/AssistantBoundary';
import {Button,Dropdown,Label,Modal,Spinner,Tooltip} from '@heroui/react';
import {ArrowLeft01Icon,ArrowRight01Icon,File01Icon,InformationCircleIcon,Search01Icon,XIcon,MoreHorizontalIcon,PrinterIcon,Share08Icon} from '@hugeicons/core-free-icons';
import {animate,type AnimationPlaybackControls} from 'motion';
import {motion as Motion,AnimatePresence,useIsPresent,useReducedMotion,useMotionValue,useMotionValueEvent,useTransform,type MotionValue} from 'motion/react';
import {useCallback,useEffect,useLayoutEffect,useRef,useState,type CSSProperties,type ReactNode} from 'react';
import type {BrowseQuery,DrawingMark,Entry,MediaReference,StudioOptions} from '../../contracts';
import {Icon,errorText,type Messages} from '../../ui';
import {entryActionItems,type EntryActionHandlers} from './EntryActions';
import {EntryInspector} from './EntryInspector';
import {FavoriteButton,type ToggleFavorite} from './FavoriteButton';
import {ShareDialog} from '../management/ShareDialog';
import {MoveDialog} from './MoveDialog';
import {PlaybackPreview} from './PlaybackPreview';
import {ImageDrawingEditor,ImageDrawingOverlay} from './ImageDrawingLayer';
import {DocumentPageControl,DocumentZoomControls,useDocumentViewport} from './DocumentViewport';
import {DocumentFind} from './DocumentFind';
import type {OfficeFindState,OfficeFindResult,OfficeViewportControl,OfficeViewportState} from '../../office-preview-contract';
import {PDFPreview,supportsPDFPreview} from './PDFPreview';
import {OfficePreview,supportsOfficePreview,supportsOfficeSearch,supportsOfficePages} from './OfficePreview';
import {PreviewTools} from './PreviewTools';

export interface PreviewAnchor {entry:Entry;rect:DOMRect|null;source:HTMLImageElement|null}

type PreviewProps = {
  anchor:PreviewAnchor|null;
  workspace:string;
  query:BrowseQuery;
  options:StudioOptions;
  portal:HTMLDivElement;
  t:Messages;
  onNavigateEntry?: (entry: Entry) => void;
  onClose:()=>void;
  onChanged:(entry:Entry)=>void;
  onTrash:(entry:Entry)=>void;
  canWrite:boolean;
  actions?:EntryActionHandlers;
  allowShare?:boolean;
  guestUploads?:boolean;
  allowDownload?:boolean;
  allowTrash?:boolean;
  showTrash?:boolean;
  toggleFavorite:ToggleFavorite|undefined;
  pendingFavorites:ReadonlySet<string>;
  inlineTarget?:HTMLDivElement;
  pickerSelect?:((entry:Entry)=>boolean)|undefined;
  pickerSelection?:ReadonlyMap<string,MediaReference>|undefined;
  pickerSelectionFull?:boolean;
};
type Direction = 'previous'|'next';
type PreviewWindow = {items:Entry[];selected:string;previous:boolean;next:boolean};
const RETAIN_EACH_SIDE = 62;
const PREFETCH_DISTANCE = 2;

export function FilePreview({anchor,...props}:PreviewProps) {
  return anchor ? <PreviewSession key={anchor.entry.id} anchor={anchor} {...props}/> : null;
}

function PreviewSession({anchor,workspace,query,options,portal,t,onClose,onNavigateEntry,onChanged,onTrash,canWrite,actions,allowShare=false,guestUploads=false,allowDownload=true,allowTrash=true,showTrash=true,toggleFavorite,pendingFavorites,inlineTarget,pickerSelect,pickerSelection,pickerSelectionFull=false}:Omit<PreviewProps,'anchor'>&{anchor:PreviewAnchor}) {
  const {transport} = options;
  const keyboardTarget=inlineTarget??portal;
  const inline=!!inlineTarget;
  const [windowState,setWindowState] = useState<PreviewWindow>({items:[anchor.entry],selected:anchor.entry.id,previous:true,next:true});
  const current = useRef(windowState);
  const requests = useRef<Partial<Record<Direction,{controller:AbortController;promise:Promise<void>}>>>({});
  const continuations = useRef<Partial<Record<Direction,{anchor:string;cursor:string}>>>({});
  const navigating = useRef(false);
  const closing = useRef(false);
  const flight = useRef<HTMLElement|null>(null);
  const visual = useRef<HTMLDivElement>(null);
  const dialogVisual = useRef<HTMLElement>(null);
  const dragY = useMotionValue(0);
  const slideDirection = useRef(1);
  const hasNavigated = useRef(false);
  useMotionValueEvent(dragY,'change',value=>{
    if(dialogVisual.current&&!closing.current){
      const height=dialogVisual.current.clientHeight-56;
      dialogVisual.current.style.setProperty('--fms-preview-chrome-opacity',String(Math.max(0,1-Math.max(0,value)/Math.max(1,height*.5))));
      dialogVisual.current.style.setProperty('--fms-preview-ui-opacity',String(Math.max(0,1-Math.abs(value)/64)));
    }
  });
  const motion = useRef<AnimationPlaybackControls|null>(null);
  const entrance = useRef<AnimationPlaybackControls|null>(null);
  const mounted = useRef(true);
  const notifyChanged = useRef(onChanged);
  notifyChanged.current = onChanged;
  const [isClosing,setIsClosing] = useState(false);
  const [videoEditing,setVideoEditing]=useState(false);
  const [videoDimensions, setVideoDimensions] = useState<{key: string; width: number; height: number; duration: number}>();
  const [inspectorTab,setInspectorTab]=useState('details');
  const assistant=useAssistantPreview();
  const [information,setInformation] = useState(()=>!inline&&portal.clientWidth>760&&!assistant.open);
  const showInformation=information&&!assistant.open;
  const previousChat=useRef(assistant.open);
  useLayoutEffect(()=>{if(!inline&&previousChat.current&&!assistant.open)setInformation(true);previousChat.current=assistant.open;},[assistant.open,inline]);
  const inspectFile=(tab='details')=>{if(inline)return;assistant.close();setInspectorTab(tab);setInformation(true);};
  const [loading,setLoading] = useState(false);
  const [error,setError] = useState('');
  const printDocument=useRef<(()=>Promise<void>)|undefined>(undefined);
  const [canPrint,setCanPrint]=useState(false);
  const onPrintReady=useCallback((print:(()=>Promise<void>)|undefined)=>{printDocument.current=print;setCanPrint(!!print);},[]);
  const [menuBusy,setMenuBusy]=useState(false);
  const [menuOpen,setMenuOpen]=useState(false);
  const menuTrigger=useRef<HTMLButtonElement>(null);
  const menuSurface=useRef<HTMLDivElement>(null);
  useEffect(()=>{
    if(!inline||!menuOpen)return;
    const outside=(event:PointerEvent)=>{
      const path=event.composedPath();
      if(!path.some(node=>node===menuTrigger.current||node===menuSurface.current))setMenuOpen(false);
    };
    const document=keyboardTarget.ownerDocument;
    document.addEventListener('pointerdown',outside,true);
    return()=>document.removeEventListener('pointerdown',outside,true);
  },[inline,keyboardTarget,menuOpen]);
  const [previewOverlays,setPreviewOverlays]=useState<HTMLDivElement|null>(null);
  const [sharing,setSharing]=useState(false);
  const [move,setMove] = useState<Entry|null>(null);

  const index = windowState.items.findIndex(item=>item.id===windowState.selected);
  const entry = windowState.items[index]!;
  const mediaKey = JSON.stringify([entry.id, entry.versionId]);
  const documentSettings=options.documentPreview===false?undefined:options.documentPreview;
  const pdfPreview=!entry.trashedAt&&supportsPDFPreview(entry,options.pdfPreview);
  const pagedOffice=!entry.trashedAt&&(pdfPreview||supportsOfficePages(entry,options.documentPreview));
  const {state:viewportState,control:viewportControl,onViewport,changeViewport}=useDocumentViewport(mediaKey);
  const onImageViewport=useCallback((state:OfficeViewportState)=>{
    // Entry clipping belongs only to the thumbnail flight, never the camera.
    if(!state.fit&&entrance.current){entrance.current.cancel();entrance.current=null;if(visual.current){delete visual.current.dataset.previewFlight;visual.current.style.transform='none';visual.current.style.clipPath='none';}}
    onViewport(state);
  },[onViewport]);
  const [find,setFind]=useState<{key:string;state:OfficeFindState;result:OfficeFindResult}>();
  const canFind=!entry.trashedAt&&(pdfPreview||supportsOfficeSearch(entry,options.documentPreview));
  const findState=find?.key===mediaKey?find.state:undefined;
  const updateFind=(state:OfficeFindState)=>setFind(previous=>({key:mediaKey,state,result:previous?.key===mediaKey&&previous.state.query===state.query?previous.result:{index:0,total:0}}));
  const closeFind=()=>setFind(previous=>previous?{...previous,state:{...previous.state,open:false}}:previous);
  const openFind=()=>updateFind(findState?{...findState,open:true}:{open:true,query:'',step:0,direction:1});
  const onFindResult=useCallback((result:OfficeFindResult)=>setFind(previous=>previous?.key===mediaKey&&(previous.result.index!==result.index||previous.result.total!==result.total)?{...previous,result}:previous),[mediaKey]);
  const [drawingSession, setDrawingSession] = useState<{key: string; revision: number}>();
  const [hiddenDrawingKey, setHiddenDrawingKey] = useState<string>();
  const [drawing, setDrawing] = useState<{key: string; marks: DrawingMark[]; revision: number}>();
  const [drawingError, setDrawingError] = useState('');
  const [savingDrawing, setSavingDrawing] = useState(false);
  const drawingRequest = useRef<AbortController | null>(null);
  const isDrawing = drawingSession?.key === mediaKey;
  const drawingHidden = hiddenDrawingKey === mediaKey;
  const currentDrawing = drawing?.key === mediaKey ? drawing : undefined;

  useEffect(() => {
    if (entry.mediaType !== 'image' || entry.trashedAt || !entry.versionId || options.previewActions === false) return;
    const controller = new AbortController();
    void transport.details(workspace, entry.id, controller.signal).then(details => {
      if (controller.signal.aborted || details.versionId !== entry.versionId) return;
      const saved = details.metadata.drawing;
      setDrawing({key: mediaKey, revision: details.revision, marks: saved?.versionId === entry.versionId ? saved.marks : []});
    }).catch(() => {
      if (!controller.signal.aborted) setDrawing(undefined);
    });
    return () => controller.abort();
  }, [mediaKey, entry.id, entry.mediaType, entry.trashedAt, entry.versionId, entry.revision, options.previewActions, transport, workspace]);

  useEffect(() => () => drawingRequest.current?.abort(), [mediaKey, workspace]);

  const captionEntry = entry.mediaType === 'video' && !(entry.width && entry.height) && videoDimensions?.key === mediaKey
    ? {...entry, width: videoDimensions.width, height: videoDimensions.height}
    : entry;
  useLayoutEffect(()=>{
    const dialog=keyboardTarget.querySelector<HTMLElement>('.fms-preview-dialog');
    const active=(portal.getRootNode() as Document|ShadowRoot).activeElement;
    if(dialog&&(!active?.closest('.fms-preview-dialog')||active.matches(':disabled')))dialog.focus({preventScroll:true});
  },[entry.id,keyboardTarget]);

  const publish = useCallback((value:PreviewWindow)=>{
    const center = value.items.findIndex(item=>item.id===value.selected);
    const start = Math.max(0,center-RETAIN_EACH_SIDE);
    const end = Math.min(value.items.length,center+RETAIN_EACH_SIDE+1);
    const bounded = {selected:value.selected,items:value.items.slice(start,end),previous:value.previous||start>0,next:value.next||end<value.items.length};
    current.current = bounded;
    setWindowState(bounded);
  },[]);

  const extend = useCallback((direction:Direction)=>{
    const pending = requests.current[direction];
    if(pending)return pending.promise;
    const boundary = direction==='next'?current.current.items.at(-1):current.current.items[0];
    if(!boundary||!current.current[direction]||closing.current||boundary.trashedAt)return Promise.resolve();
    const controller = new AbortController();
    const promise = (async()=>{
      // A hidden source row may fill an entire page. Follow a bounded number of
      // continuation pages without exposing its identity or dropping navigation.
      for(let batch=0;batch<3;batch++){
        const token = continuations.current[direction];
        const page = await transport.neighbors(workspace,boundary.id,query,direction,controller.signal,token?.anchor===boundary.id?token.cursor:undefined);
        if(controller.signal.aborted)return;
        const latest = current.current;
        const edge = direction==='next'?latest.items.at(-1):latest.items[0];
        if(edge?.id!==boundary.id)return;
        const known = new Set(latest.items.map(item=>item.id));
        const incoming = page.items.filter(item=>!known.has(item.id));
        const cursor = direction==='next'?page.nextCursor:page.previousCursor;
        const newEdge = direction==='next'?incoming.at(-1):incoming[0];
        if(cursor&&page.hasMore)continuations.current[direction] = {anchor:newEdge?.id??boundary.id,cursor};
        else delete continuations.current[direction];
        publish({...latest,[direction]:page.hasMore&&(incoming.length>0||!!cursor),items:direction==='next'?[...latest.items,...incoming]:[...incoming,...latest.items]});
        if(incoming.length||!cursor||!page.hasMore)return;
      }
    })().finally(()=>{
      if(requests.current[direction]?.controller===controller)delete requests.current[direction];
    });
    requests.current[direction] = {controller,promise};
    return promise;
  },[publish,query,transport,workspace]);

  useEffect(()=>{
    mounted.current = true;
    return ()=>{
      mounted.current = false;
      for(const request of Object.values(requests.current))request.controller.abort();
      requests.current = {};
      motion.current?.stop();
      flight.current?.remove();
    };
  },[]);
  useEffect(()=>{
    if(isClosing||entry.trashedAt)return;
    if(index<=PREFETCH_DISTANCE&&windowState.previous)void extend('previous').catch(()=>{});
    if(windowState.items.length-index-1<=PREFETCH_DISTANCE&&windowState.next)void extend('next').catch(()=>{});
  },[entry.id,entry.trashedAt,extend,index,isClosing,windowState.items.length,windowState.next,windowState.previous]);

  useEffect(()=>{
    const pending = [entry.thumbnailState,entry.previewState].some(state=>state==='queued'||state==='processing');
    if(!pending||isClosing||!transport.status)return;
    const controller = new AbortController();
    let timer:ReturnType<typeof setTimeout>;
    const refresh = async()=>{
      try{
        if(document.visibilityState==='hidden')return;
        const result = await transport.status(workspace,[entry.id],controller.signal);
        if(controller.signal.aborted)return;
        const updated = result.items.find(item=>item.id===entry.id);
        const selected = current.current.items.find(item=>item.id===entry.id);
        if(!updated||!selected||updated.revision<selected.revision)return;
        publish({...current.current,items:current.current.items.map(item=>item.id===updated.id?{...updated,...(item.starred===undefined?{}:{starred:item.starred})}:item)});
        notifyChanged.current({...updated,...(selected.starred===undefined?{}:{starred:selected.starred})});
      }catch{
        // The existing preview remains usable while a later status request retries.
      }finally{
        if(!controller.signal.aborted)timer=setTimeout(()=>void refresh(),2500);
      }
    };
    timer=setTimeout(()=>void refresh(),2500);
    return()=>{clearTimeout(timer);controller.abort();};
  },[entry.id,entry.previewState,entry.thumbnailState,isClosing,publish,transport,workspace]);

  const navigate = async(direction:Direction)=>{
    if(navigating.current||closing.current||entry.trashedAt||isDrawing||videoEditing)return;
    const adjacent = ()=>{
      const state = current.current;
      const position = state.items.findIndex(item=>item.id===state.selected);
      return state.items[position+(direction==='next'?1:-1)];
    };
    const select = (item:Entry)=>{hasNavigated.current=true;slideDirection.current=direction==='next'?1:-1;dragY.jump(0);publish({...current.current,selected:item.id});onNavigateEntry?.(item);};
    setError('');
    const cached = adjacent();
    if(cached){select(cached);return;}
    if(!current.current[direction])return;
    navigating.current = true;
    setLoading(true);
    try{
      await extend(direction);
      const item = adjacent();
      if(item&&!closing.current&&mounted.current)select(item);
    }catch(error){
      if(!closing.current&&mounted.current)setError(errorText(error));
    }finally{
      navigating.current = false;
      if(mounted.current)setLoading(false);
    }
  };

  useEffect(()=>{
    const handleArrow = (event:KeyboardEvent)=>{
      if(videoEditing||isDrawing)return;
      const target = event.target;
      if(!(target instanceof HTMLElement)||event.defaultPrevented||event.altKey||event.ctrlKey||event.metaKey||target.closest('input,textarea,[contenteditable=true],audio,video,.fms-office-preview,.fms-assistant,.fms-assistant-history-drawer,[role=menu],[role=listbox]') || event.composedPath().some(node => node instanceof HTMLMediaElement))return;
      const dialog = target.closest('.fms-preview-dialog');
      if(!dialog||(event.key!=='ArrowRight'&&event.key!=='ArrowLeft'))return;
      event.preventDefault();
      const rtl = getComputedStyle(dialog).direction==='rtl';
      void navigate((event.key==='ArrowRight')!==rtl?'next':'previous');
    };
    keyboardTarget.addEventListener('keydown',handleArrow);
    return ()=>keyboardTarget.removeEventListener('keydown',handleArrow);
  });

  useLayoutEffect(()=>{
    const target = visual.current;
    const from = anchor.rect;
    if(!target||!from||!anchor.source?.complete||!anchor.source.naturalWidth||reducedMotion())return;
    const to = target.getBoundingClientRect();
    if(!to.width||!to.height||!from.width||!from.height)return;
    const first = thumbnailFrame(to,from);
    target.dataset.previewFlight='true';
    const flight=animate(target,{transform:[first.transform as string,'translate(0px,0px) scale(1)'],clipPath:[first.clipPath as string,'inset(0% 0% round 0px)']},{type:'spring',visualDuration:.42,bounce:.06});
    motion.current=flight;entrance.current=flight;
    void flight.then(()=>{
      if(entrance.current!==flight||closing.current)return;
      flight.cancel();entrance.current=null;
      delete target.dataset.previewFlight;target.style.transform='none';target.style.clipPath='none';
    }).catch(()=>{});
  },[anchor]);

  const close = ()=>{
    if(closing.current)return;
    closing.current = true;
    setIsClosing(true);
    if(dialogVisual.current&&!reducedMotion())animate(dialogVisual.current,{'--fms-preview-chrome-opacity':0,'--fms-preview-ui-opacity':0},{duration:.3});
    for(const request of Object.values(requests.current))request.controller.abort();
    const root = portal.getRootNode() as Document|ShadowRoot;
    const source = (entry.id===anchor.entry.id ? anchor.source : null) ?? root.querySelector<HTMLImageElement>(`[data-photo-id="${CSS.escape(entry.id)}"] img,[data-preview-entry="${CSS.escape(entry.id)}"] img`);
    const target = visual.current;
    if(!target||!source?.isConnected||reducedMotion()){onClose();return;}
    motion.current?.stop();
    // Restore the destination cell before measuring, as navigation can move it outside the grid viewport.
    const cell=source.getBoundingClientRect();
    if(cell.top<0||cell.bottom>window.innerHeight||cell.left<0||cell.right>window.innerWidth)source.scrollIntoView({block:'nearest',inline:'nearest',behavior:'instant'});
    const imagePlane=target.querySelector<HTMLElement>('.fms-preview-image-pan');
    // Snapshot the currently painted camera, including an in-flight zoom/pan.
    // The fitted wrapper is only a layout anchor and is not the visible image.
    const from = (imagePlane??target).getBoundingClientRect(),to = source.getBoundingClientRect();
    const viewport=target.closest('.fms-preview-pages')?.getBoundingClientRect();
    const initialClip=viewport?`inset(${Math.max(0,viewport.top-from.top)/from.height*100}% ${Math.max(0,from.right-viewport.right)/from.width*100}% ${Math.max(0,from.bottom-viewport.bottom)/from.height*100}% ${Math.max(0,viewport.left-from.left)/from.width*100}% round 0px)`:'inset(0% 0% 0% 0% round 0px)';
    const inView = to.bottom>0&&to.top<window.innerHeight&&to.right>0&&to.left<window.innerWidth;
    if(!from.width||!from.height||!to.width||!to.height||!inView){onClose();return;}
    // Freeze the actually displayed geometry, including an unfinished drag/scale.
    // The return layer is outside the fading chrome and the clipped slide viewport.
    const returning = target.cloneNode(true) as HTMLDivElement;
    returning.setAttribute('aria-hidden','true');
    returning.classList.add('fms-preview-return');
    returning.classList.remove('has-image-camera');
    const returnPlane=returning.querySelector<HTMLElement>('.fms-preview-image-pan');
    if(returnPlane){returnPlane.style.transform='none';returnPlane.style.willChange='auto';}
    delete returning.dataset.previewFlight;
    Object.assign(returning.style,{position:'fixed',left:`${from.left}px`,top:`${from.top}px`,width:`${from.width}px`,height:`${from.height}px`,transform:'none',clipPath:initialClip,zIndex:'1000',pointerEvents:'none',margin:'0'});
    portal.append(returning);
    flight.current=returning;
    target.style.visibility='hidden';
    const end = thumbnailFrame(from,to);
    const exit = animate(returning,{transform:['translate(0px,0px) scale(1)',end.transform as string],clipPath:[initialClip,end.clipPath as string]},{type:'spring',visualDuration:.38,bounce:0});
    motion.current = exit;
    void exit.then(()=>{returning.remove();if(mounted.current)onClose();}).catch(()=>{returning.remove();});
  };

  useEffect(()=>{
    let last=0,total=0,committed=false;
    const wheel=(event:WheelEvent)=>{
      if(!(event.target instanceof Element)||!event.target.closest('.fms-preview-visual')||closing.current)return;
      if(event.ctrlKey){event.preventDefault();return;}
      if(Math.abs(event.deltaX)<=Math.abs(event.deltaY))return;
      event.preventDefault();
      if(event.timeStamp-last>180){total=0;committed=false;}
      last=event.timeStamp;total+=event.deltaX;
      if(!committed&&Math.abs(total)>36){committed=true;void navigate(total>0?'next':'previous');}
    };
    keyboardTarget.addEventListener('wheel',wheel,{passive:false});
    return()=>keyboardTarget.removeEventListener('wheel',wheel);
  });

  const changed = (value:Entry)=>{
    publish({...current.current,items:current.current.items.map(item=>item.id===value.id?{...item,...value}:item)});
    onChanged(value);
  };
  const canPrevious = index>0||windowState.previous;
  const canNext = index<windowState.items.length-1||windowState.next;
  const editable = canWrite&&!entry.trashedAt&&entry.readOnly!==true;
  const pendingImage = entry.thumbnailUrl && ['queued', 'processing'].includes(entry.previewState ?? '');
  const videoProcessing = entry.mediaType === 'video' && ['queued', 'processing'].includes(entry.previewState ?? '');
  const previewUrl = entry.mediaType === 'image'
    ? entry.previewUrl ?? (pendingImage ? null : transport.contentUrl(workspace, entry.id, false, entry.versionId ?? undefined))
    : null;
  const playable = !entry.trashedAt && (entry.mediaType === 'audio' || entry.mediaType === 'video');

  const previewCommands=useRef<FilePreviewHandle>(null!);
  previewCommands.current={
    getEntry:()=>({...entry}),getViewport:()=>({...viewportState}),close,
    navigate:async direction=>{if(mounted.current)await navigate(direction);},
    setZoom:value=>{if(mounted.current&&!closing.current)changeViewport({zoom:{value,request:0}});},
    showPanel:panel=>{
      if(!mounted.current||closing.current)return false;
      if(inline)return panel==='none';
      if(panel==='assistant'){if(!assistant.enabled)return false;if(!assistant.open)assistant.toggle();return true;}
      if(panel==='comments'){if(options.comments===false||!transport.comments||entry.trashedAt)return false;inspectFile('comments');return true;}
      if(panel==='information'){inspectFile();return true;}
      previousChat.current=false;assistant.close();setInformation(false);return true;
    },
    search:query=>{if(!mounted.current||!canFind||closing.current)return false;updateFind({open:true,query,step:0,direction:1});return true;},
    print:async()=>{if(!mounted.current||!allowDownload||!canPrint||!printDocument.current||closing.current)return false;await printDocument.current();return true;},
    rename:async(name,signal)=>{if(!mounted.current||!editable||closing.current)throw new Error(t.previewUnavailable);const value=await transport.update(workspace,entry.id,{revision:entry.revision,name},signal);if(!signal.aborted)changed(value);return value;},
  };
  const [previewHandle]=useState<FilePreviewHandle>(()=>({
    getEntry:()=>previewCommands.current.getEntry(),getViewport:()=>previewCommands.current.getViewport(),
    close:()=>previewCommands.current.close(),navigate:direction=>previewCommands.current.navigate(direction),
    setZoom:value=>previewCommands.current.setZoom(value),showPanel:panel=>previewCommands.current.showPanel(panel),
    search:query=>previewCommands.current.search(query),print:()=>previewCommands.current.print(),rename:(name,signal)=>previewCommands.current.rename(name,signal),
  }));
  useLayoutEffect(()=>{options.onPreviewReady?.(previewHandle);return()=>options.onPreviewReady?.(null);},[options.onPreviewReady,previewHandle]);

  const previewKeyboard=useRef<(event:KeyboardEvent)=>void>(()=>{});
  previewKeyboard.current=event=>{
          if(entry.mediaType==='image'&&(event.metaKey||event.ctrlKey)&&['+','=','-','0'].includes(event.key)&&!(event.target as HTMLElement).closest('input,textarea,[contenteditable="true"]')){event.preventDefault();event.stopPropagation();changeViewport({zoom:{value:event.key==='0'?'fit':Math.max(viewportState.minZoom??.05,Math.min(viewportState.maxZoom??8,viewportState.zoom*(event.key==='-'?.75:1.25))),request:0}});}
          else if(canFind&&(event.metaKey||event.ctrlKey)&&event.key.toLowerCase()==='f'){event.preventDefault();event.stopPropagation();openFind();}
          else if(inline&&menuOpen&&event.key==='Escape'){event.preventDefault();event.stopPropagation();setMenuOpen(false);menuTrigger.current?.focus();}
          else if(findState?.open&&event.key==='Escape'){event.preventDefault();event.stopPropagation();closeFind();}
          else if(inline&&event.key==='Escape'&&!event.defaultPrevented){event.preventDefault();event.stopPropagation();close();}
        };
  useEffect(()=>{const handle=(event:KeyboardEvent)=>previewKeyboard.current(event);keyboardTarget.addEventListener('keydown',handle,true);return()=>keyboardTarget.removeEventListener('keydown',handle,true);},[keyboardTarget]);

  const previewHandlers:EntryActionHandlers=inline?{...actions}:{...actions,inspect:()=>inspectFile()};
  if(allowShare&&transport.management?.createShare)previewHandlers.share=()=>setSharing(true);else delete previewHandlers.share;
  if(editable&&actions?.move)previewHandlers.move=()=>setMove(entry);
  if(!allowDownload)delete previewHandlers.download;
  if(!allowTrash||!showTrash)delete previewHandlers.trash;
  const Header=inline?'header':Modal.Header;
  const preview=<>
          <div ref={dialogVisual as React.RefObject<HTMLDivElement|null>} className={`fms-preview-surface fms-preview-themed${!inline&&(information||assistant.open)?' has-inspector':''}`} style={{'--fms-chat-space':assistant.panelWidth+'px'} as CSSProperties} data-theme={options.theme??'light'} data-assistant-enabled={!inline&&assistant.enabled||undefined}>
          <Header className={inline?"fms-preview-header modal__header":"fms-preview-header"}>
            <div className={`fms-preview-title${pagedOffice?' is-document':''}`}><div className="fms-preview-name"><PreviewFileName key={entry.id} entry={entry} editable={editable&&options.previewTitleEditable!==false&&!isClosing} t={t} onRename={async(name,signal)=>{const value=await transport.update(workspace,entry.id,{revision:entry.revision,name},signal);if(!signal.aborted)changed(value);}}/>{pagedOffice&&<DocumentPageControl key={mediaKey} state={viewportState} onChange={changeViewport} t={t}/>}</div></div>
            {!isDrawing&&(entry.mediaType==='image'&&options.imagePreview?.zoomControls!=='hidden'||pagedOffice&&(documentSettings?.zoomControls??'header')==='header')&&<DocumentZoomControls state={viewportState} onChange={command=>changeViewport(entry.mediaType==='image'&&command.zoom?{zoom:{...command.zoom,center:true}}:command)} t={t} portal={portal} {...(entry.mediaType==='image'?{minZoom:viewportState.minZoom??viewportState.zoom,maxZoom:viewportState.maxZoom??8,multiplicative:true,actualSizeOnly:true}:{})}/>}
            <div className="fms-preview-tools">
              {pickerSelect&&!entry.trashedAt&&entry.versionId&&<Button size="sm" className="fms-preview-select" isDisabled={isClosing||(pickerSelectionFull&&!pickerSelection?.has(entry.id))} onPress={()=>{if(pickerSelect(entry))close();}}>{t.select}</Button>}

              {toggleFavorite&&!entry.trashedAt&&<FavoriteButton entry={entry} t={t} pending={pendingFavorites.has(entry.id)} iconSize={20} iconStrokeWidth={1.5} onToggle={()=>toggleFavorite(entry,starred=>{
                if(!mounted.current)return;
                publish({...current.current,items:current.current.items.map(item=>item.id===entry.id?{...item,starred}:item)});
              })}/>}
              {canFind&&<Tooltip delay={300}><Button className="fms-document-find-trigger" isIconOnly variant="ghost" size="sm" aria-label={t.findDocument} aria-pressed={findState?.open===true} isDisabled={isClosing} onPress={()=>findState?.open?closeFind():openFind()}><Icon icon={Search01Icon} size={20} strokeWidth={1.5}/></Button><Tooltip.Content UNSTABLE_portalContainer={portal}>{t.findDocument}</Tooltip.Content></Tooltip>}

              {options.previewMenu!==false&&(!inline||allowDownload||!!options.previewMenu)&&<Dropdown isOpen={menuOpen} onOpenChange={setMenuOpen}><Button ref={menuTrigger} isIconOnly variant="ghost" size="sm" aria-label={t.moreActions}
                onPointerDownCapture={event=>{if(inline&&menuOpen&&event.button===0){event.preventDefault();event.stopPropagation();setMenuOpen(false);}}} isDisabled={isClosing||menuBusy}><Icon icon={MoreHorizontalIcon} size={20} strokeWidth={1.5}/></Button>
                <Dropdown.Popover ref={menuSurface} isNonModal placement="bottom end" UNSTABLE_portalContainer={previewOverlays??portal}><Dropdown.Menu aria-label={t.moreActions} onAction={()=>setMenuOpen(false)}>
                  {entryActionItems(entry,t,previewHandlers,undefined,toggleFavorite?()=>toggleFavorite(entry,starred=>{if(mounted.current)publish({...current.current,items:current.current.items.map(item=>item.id===entry.id?{...item,starred}:item)});}):undefined,pendingFavorites.has(entry.id)).map(action=><Dropdown.Item key={action.id} id={action.id} textValue={action.text} onAction={()=>action.run?.(entry)}><Icon icon={action.icon} size={20}/><Label>{action.text}</Label></Dropdown.Item>)}
                  {(pdfPreview||supportsOfficePreview(entry,options.documentPreview))&&(options.documentPreview===false||options.documentPreview?.print!==false)&&allowDownload&&<Dropdown.Item id="print" textValue={t.print} isDisabled={!canPrint||menuBusy} onAction={()=>{setMenuBusy(true);void printDocument.current?.().catch(reason=>setError(errorText(reason))).finally(()=>{if(mounted.current)setMenuBusy(false);});}}><Icon icon={PrinterIcon} size={20}/><Label>{t.print}</Label></Dropdown.Item>}
                  {(typeof options.previewMenu==='function'?options.previewMenu(entry):options.previewMenu??[]).filter(action=>action.visible?.(entry)!==false).map(action=><Dropdown.Item key={action.id} id={'custom:'+action.id} textValue={action.label??action.id} onAction={()=>{try{action.onPress?.({entry,workspace});}catch(reason){setError(errorText(reason));}}}>{action.icon&&<Icon icon={action.icon} size={20}/>}<Label>{action.label??action.id}</Label></Dropdown.Item>)}
                </Dropdown.Menu></Dropdown.Popover></Dropdown>}
              <Tooltip delay={300}><Button className="fms-preview-back" isIconOnly variant="ghost" size="sm" aria-label={t.close} isDisabled={isClosing} onPress={close}><Icon icon={XIcon} size={20} strokeWidth={1.5}/></Button><Tooltip.Content UNSTABLE_portalContainer={portal}>{t.close}</Tooltip.Content></Tooltip>
            </div>
          </Header>
          <div className={`fms-preview-layout${!inline&&(information||assistant.open)?' has-inspector':''}`}>
            <div className={`fms-preview-stage${entry.mediaType==='video'&&options.previewActions!==false?' has-video-tools':''}`} data-video-editing={videoEditing||undefined}>
              {canFind&&findState?.open&&<DocumentFind state={findState} result={find?.result??{index:0,total:0}} onChange={updateFind} onClose={closeFind} t={t} portal={portal}/>}
              <div className="fms-preview-pages">
                <AnimatePresence initial={false} custom={slideDirection.current}>
                  {entry.mediaType==='image' ? <PreviewImage key={`${entry.id}:${entry.versionId}`} entry={entry} visual={visual}
                    editing={isDrawing} settings={options.imagePreview} viewport={viewportControl} onViewport={onImageViewport} source={entry.id===anchor.entry.id?anchor.source:null} previewUrl={previewUrl} t={t} onDismiss={close} onNavigate={direction=>void navigate(direction)} disabled={isClosing||isDrawing} overlay={isDrawing && currentDrawing ? <ImageDrawingEditor
                      comments={options.comments!==false&&transport.comments&&entry.versionId?{workspace,entry:entry.id,versionId:entry.versionId,transport:transport.comments,viewer:options.user}:undefined} initial={currentDrawing.marks} portal={portal} t={t} busy={savingDrawing} error={drawingError}
                      onCancel={() => setDrawingSession(undefined)} onSave={marks => {
                        if (!canWrite || !entry.versionId || !drawingSession || savingDrawing) return;
                        const controller = new AbortController();
                        drawingRequest.current = controller;
                        setDrawingError('');
                        setSavingDrawing(true);
                        void transport.update(workspace, entry.id, {revision: drawingSession.revision, drawing: {versionId: entry.versionId, marks}}, controller.signal)
                          .then(updated => {
                            if (controller.signal.aborted) return;
                            setDrawing({key: mediaKey, marks, revision: updated.revision});
                            setDrawingSession(undefined);
                            setSavingDrawing(false);
                            changed(updated);
                          }).catch(reason => {
                            if (!controller.signal.aborted) {setSavingDrawing(false);setDrawingError(errorText(reason));}
                          });
                      }}/>
                      : !drawingHidden && currentDrawing?.marks.length ? <ImageDrawingOverlay marks={currentDrawing.marks}/> : null} dragY={dragY} previous={windowState.items[index-1]} next={windowState.items[index+1]} slideDirection={hasNavigated.current?slideDirection.current:0}
                  /> : <PreviewMediaPage key={`${entry.id}:${entry.versionId}`} slideDirection={hasNavigated.current?slideDirection.current:0}>
                    {videoProcessing
                      ? <div className="fms-document-preview" role="status"><Spinner/><p>{t.processingVideo}</p></div>
                      : playable && !videoEditing
                        ? <PlaybackPreview entry={entry} onDimensions={(width, height, duration) => {
                            if (current.current.selected === entry.id) setVideoDimensions({key: mediaKey, width, height, duration});
                          }} skinVariant={options.videoPreviewSkin ?? 'default'} active={!isClosing&&!videoEditing}
                            url={entry.previewUrl ?? transport.contentUrl(workspace, entry.id, false, entry.versionId ?? undefined)} t={t}/>
                        : pdfPreview
                          ? <PDFPreview onPrintReady={onPrintReady} search={findState} onSearchResult={onFindResult} entry={entry} url={transport.contentUrl(workspace,entry.id,false,entry.versionId??undefined)} t={t} active={!isClosing} settings={options.pdfPreview||{}} viewport={viewportControl} onViewport={onViewport}/>
                        : options.documentPreview !== false && !entry.trashedAt && supportsOfficePreview(entry, options.documentPreview)
                          ? <OfficePreview theme={options.theme??'light'} onPrintReady={onPrintReady} entry={entry} url={transport.contentUrl(workspace,entry.id,false,entry.versionId ?? undefined)} t={t} active={!isClosing} settings={options.documentPreview ?? {}} search={findState} onSearchResult={onFindResult} viewport={viewportControl} onViewport={onViewport}/>
                          : <div className="fms-document-preview"><Icon icon={File01Icon} size={80}/><p>{entry.mimeType}</p></div>}
                  </PreviewMediaPage>}
                </AnimatePresence>
              </div>
              {!entry.trashedAt&&<>
                <Button isIconOnly variant="tertiary" className="fms-preview-previous" aria-label={t.previousFile} isDisabled={loading||isClosing||isDrawing||!canPrevious} onPress={()=>void navigate('previous')}><span className="fms-preview-icon"><Icon icon={ArrowLeft01Icon}/></span></Button>
                <Button isIconOnly variant="tertiary" className="fms-preview-next" aria-label={t.nextFile} isDisabled={loading||isClosing||isDrawing||!canNext} onPress={()=>void navigate('next')}><span className="fms-preview-icon"><Icon icon={ArrowRight01Icon}/></span></Button>
              </>}
              <PreviewTools entry={entry} workspace={workspace} options={options} portal={portal} t={t}
                closing={isClosing} editing={videoEditing || isDrawing}
                canDownload={allowDownload&&!isClosing}
                canComments={options.comments!==false&&!!transport.comments&&!isClosing}
                commentsActive={showInformation&&inspectorTab==='comments'}
                onDownload={()=>{window.location.href=transport.contentUrl(workspace,entry.id,true);}}
                onComments={()=>{if(showInformation&&inspectorTab==='comments')setInformation(false);else inspectFile('comments');}}
                canDraw={canWrite && !!entry.versionId && !isClosing}
                drawingReady={!!currentDrawing && !!previewUrl}
                annotationsVisible={!drawingHidden} hasAnnotations={!!currentDrawing?.marks.length}
                onDraw={() => {
                  if (!currentDrawing) return;
                  setDrawingSession({key: mediaKey, revision: currentDrawing.revision});
                  setDrawingError('');
                  setSavingDrawing(false);
                  setHiddenDrawingKey(undefined);
                }}
                onToggleAnnotations={() => setHiddenDrawingKey(drawingHidden ? undefined : mediaKey)} showTrash={showTrash} canTrash={editable&&allowTrash!==false} onTrash={onTrash} onChanged={changed} onEditing={setVideoEditing}/>
              {loading&&<div role="status" className="fms-preview-navigation-busy"><Spinner size="sm" aria-label={t.loading}/></div>}
              {error&&<p className="fms-preview-error" role="alert">{error}</p>}
            </div>
            {information&&<EntryInspector hidden={assistant.open} activeTab={inspectorTab} onTabChange={setInspectorTab} portal={portal} tagPortal={previewOverlays??portal} comments={options.comments!==false} key={entry.id} entry={captionEntry} duration={videoDimensions?.key === mediaKey ? videoDimensions.duration : undefined} workspace={workspace} transport={transport} canWrite={editable} onChanged={changed} t={t}/>}
          </div>

          </div>
          {!inline&&<AssistantPreviewSlot/>}
          <div ref={setPreviewOverlays} className="fms-preview-overlays"/>
  </>;
  return <>
    {inlineTarget?createPortal(<InlinePreview target={inlineTarget} name={entry.name}>{preview}</InlinePreview>,inlineTarget):
      <Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open)close();}} UNSTABLE_portalContainer={portal} className="fms-preview-backdrop">
        <Modal.Container className="fms-preview-container" placement="center">
          <Modal.Dialog className="fms-preview-dialog" aria-label={entry.name}>{preview}</Modal.Dialog>
        </Modal.Container>
      </Modal.Backdrop></Modal>}
    {sharing&&previewOverlays&&transport.management&&<ShareDialog workspace={workspace} entries={[entry]} guestUploads={guestUploads} transport={transport.management} portal={previewOverlays} locale={options.locale??'en'} onClose={()=>setSharing(false)}/>}
    <MoveDialog entry={move} workspace={workspace} transport={transport} portal={previewOverlays??portal} t={t} onClose={()=>setMove(null)} onChanged={value=>{changed(value);close();}}/>
  </>;
}

function InlinePreview({target,name,children}:{target:HTMLDivElement;name:string;children:ReactNode}) {
  const section=useRef<HTMLElement>(null);
  useLayoutEffect(()=>{
    const scope=target.getRootNode() as Document|ShadowRoot;
    const previous=scope.activeElement;
    const library=target.parentElement?.querySelector<HTMLElement>(':scope > .fms-main');
    const wasInert=library?.inert??false;
    if(library)library.inert=true;
    section.current?.focus({preventScroll:true});
    return()=>{
      if(library)library.inert=wasInert;
      if(previous instanceof HTMLElement&&previous.isConnected)previous.focus({preventScroll:true});
    };
  },[target]);
  return <section ref={section} className="fms-preview-dialog fms-preview-inline" tabIndex={-1} aria-label={name}>{children}</section>;
}

function previewSlide(reduce: boolean | null) {
  return {
    variants: {
      enter: (direction: number) => ({x: reduce ? 0 : `calc(${direction * 100}% + ${direction * 24}px)`}),
      center: {x: 0},
      leave: (direction: number) => ({x: reduce ? 0 : `calc(${-direction * 100}% - ${direction * 24}px)`}),
    },
    transition: reduce ? {duration: 0} : {type: 'spring' as const, visualDuration: .34, bounce: .04},
  };
}

function PreviewMediaPage({children, slideDirection}: {children: React.ReactNode; slideDirection: number}) {
  const present = useIsPresent();
  const reduce = useReducedMotion();
  return <Motion.div className="fms-preview-media-page" data-preview-active={present} inert={!present}
    aria-hidden={!present} custom={slideDirection} {...previewSlide(reduce)}
    initial={slideDirection ? 'enter' : false} animate="center" exit="leave">
    {children}
  </Motion.div>;
}

function PreviewImage({entry,visual,source,previewUrl,t,onDismiss,onNavigate,disabled,dragY,slideDirection,previous,next,overlay,viewport,onViewport,settings,editing}:{editing:boolean;settings:ImagePreviewSettings|undefined;viewport:OfficeViewportControl|undefined;onViewport:(state:OfficeViewportState)=>void;overlay:React.ReactNode;entry:Entry;visual:React.RefObject<HTMLDivElement|null>;source:HTMLImageElement|null;previewUrl:string|null;t:Messages;onDismiss:()=>void;onNavigate:(direction:Direction)=>void;disabled:boolean;dragY:MotionValue<number>;slideDirection:number;previous:Entry|undefined;next:Entry|undefined}) {
  const reduce = useReducedMotion();
  const ownVisual = useRef<HTMLDivElement|null>(null);
  const dragX = useMotionValue(0);
  const dragScale = useTransform(dragY,value=>1-.42*Math.min(1,Math.max(0,value)/Math.max(1,ownVisual.current?.closest('.fms-preview-stage')?.clientHeight??600)));
  const axis = useRef<'x'|'y'|null>(null);
  const present = useIsPresent();
  const [horizontal,setHorizontal] = useState(false);
  const frame=useRef<HTMLDivElement|null>(null),plane=useRef<HTMLDivElement|null>(null);
  const camera=useRef<ReturnType<typeof createImageViewport>|null>(null);
  const [natural,setNatural]=useState<{width:number;height:number}>();
  const [panning,setPanning]=useState(false),[transforming,setTransforming]=useState(false);
  const callbacks=useRef({onViewport,settings,entry});callbacks.current={onViewport,settings,entry};
  useLayoutEffect(()=>{
    if(!plane.current||!frame.current)return;
    const handle=createImageViewport(plane.current,frame.current,settings??{},active=>{setTransforming(active);if(active){dragX.set(0);dragY.set(0);}});camera.current=handle;
    let announced=false;
    const unsubscribe=handle.subscribe(state=>{
      if(!state.ready)return;
      const {onViewport,settings,entry}=callbacks.current;
      setPanning(state.zoom>state.fitZoom+.001);
      onViewport({page:1,total:1,zoom:state.zoom,fit:state.fit,minZoom:state.minZoom,maxZoom:state.maxZoom});settings?.onViewport?.(state,entry);
      if(!announced){announced=true;settings?.onReady?.(handle,entry);}
    });
    return()=>{unsubscribe();handle.destroy();camera.current=null;if(announced)callbacks.current.settings?.onReady?.(null,callbacks.current.entry);};
  },[settings?.minZoom,settings?.maxZoom,settings?.animationMs,settings?.gestures,settings?.wheelBehavior,settings?.fitPadding,settings?.maxRelativeZoom,dragX,dragY]);
  useLayoutEffect(()=>{if(natural){const ratio=entry.width&&entry.height?entry.width/entry.height:0;const intrinsic=ratio&&Math.abs(ratio-natural.width/natural.height)<.02&&entry.width&&entry.height?{width:entry.width,height:entry.height}:natural;camera.current?.setImageSize(intrinsic.width,intrinsic.height);}},[natural,entry.width,entry.height,settings?.minZoom,settings?.maxZoom,settings?.animationMs,settings?.gestures,settings?.wheelBehavior,settings?.fitPadding,settings?.maxRelativeZoom]);
  useEffect(()=>{if(viewport?.zoom)camera.current?.zoomTo(viewport.zoom.value,{center:viewport.zoom.center===true});},[viewport?.zoom?.request]);
  useEffect(()=>{camera.current?.setEnabled(!disabled);if(editing)camera.current?.zoomTo('fit',{animate:false});},[disabled,editing]);
  const visualRef = useCallback((node:HTMLDivElement|null)=>{
    if(node){ownVisual.current=node;visual.current=node;}
    else if(visual.current===ownVisual.current)visual.current=null;
  },[visual]);
  const [aspect,setAspect] = useState(()=>entry.width&&entry.height?entry.width/entry.height:source?.naturalWidth&&source.naturalHeight?source.naturalWidth/source.naturalHeight:1);
  const [thumbnailFailed,setThumbnailFailed] = useState(false);
  const [imageState,setImageState] = useState<'loading'|'ready'|'failed'>('loading');
  const imageRef = useRef<HTMLImageElement>(null);
  const showThumbnail = Boolean(entry.thumbnailUrl&&!thumbnailFailed);
  const updateAspect = (image:HTMLImageElement)=>{
    if(image.naturalWidth&&image.naturalHeight)setAspect(image.naturalWidth/image.naturalHeight);
  };
  return <Motion.div ref={frame} className="fms-preview-picture has-image-camera" data-preview-active={present} aria-hidden={!present} custom={slideDirection} {...previewSlide(reduce)} initial={slideDirection?'enter':false} animate="center" exit="leave">
    <Motion.div className="fms-preview-drag" style={{x:dragX,y:dragY,scale:dragScale}} drag={disabled||panning||transforming?false:true} onDrag={(_event,info)=>{
      if(!axis.current&&Math.hypot(info.offset.x,info.offset.y)>=6){axis.current=Math.abs(info.offset.x)>Math.abs(info.offset.y)?'x':'y';setHorizontal(axis.current==='x');}
      if(axis.current==='x')dragY.set(0);
    }} dragConstraints={{top:0,bottom:0,left:0,right:0}} dragElastic={{left:next?1:.18,right:previous?1:.18,top:.25,bottom:1}} dragSnapToOrigin dragTransition={{bounceStiffness:420,bounceDamping:38}} onPointerDown={event=>{const rect=event.currentTarget.getBoundingClientRect();event.currentTarget.style.transformOrigin=`${event.clientX-rect.left}px ${event.clientY-rect.top}px`;}} onDragEnd={(event,info)=>{
      if(event.type==='pointercancel'){axis.current=null;return;}
      if(axis.current==='x'&&(Math.abs(info.offset.x)>((ownVisual.current?.closest('.fms-preview-stage')?.clientWidth??600)+24)*.5||Math.abs(info.velocity.x)>240&&Math.sign(info.velocity.x)===Math.sign(info.offset.x))){
        const rtl=getComputedStyle(visual.current!).direction==='rtl';
        const direction=(info.offset.x<0)!==rtl?'next':'previous';
        if(direction==='next'?next:previous){dragX.stop();onNavigate(direction);}
      }else if(axis.current==='y'&&(info.offset.y+.18*info.velocity.y>Math.min(140,.22*(ownVisual.current?.closest('.fms-preview-stage')?.clientHeight??600))&&info.velocity.y> -240)){dragY.stop();onDismiss();}
      axis.current=null;
    }}>
    <div ref={visualRef} className="fms-preview-visual has-image-camera" style={{'--fms-preview-aspect':aspect} as CSSProperties}>
    <div ref={plane} className="fms-preview-image-pan">
      {!previewUrl&&!showThumbnail&&<div className="fms-document-preview"><Icon icon={File01Icon} size={80}/></div>}
      {showThumbnail&&<img src={entry.thumbnailUrl!} alt="" aria-hidden="true" className="fms-preview-placeholder"
        onLoad={event=>{if(imageState==='loading'&&(!entry.width||!entry.height))updateAspect(event.currentTarget);}} onError={()=>setThumbnailFailed(true)}/>}
      {previewUrl&&<img ref={imageRef} src={previewUrl} alt={entry.name} aria-hidden={imageState!=='ready'} decoding="async" className={`fms-preview-original${imageState==='ready'?' is-ready':''}`}
        onLoad={event=>{
          const image = event.currentTarget;
          void image.decode().then(()=>{
            if(imageRef.current!==image)return;
            updateAspect(image);
            setNatural({width:image.naturalWidth,height:image.naturalHeight});
            setImageState('ready');
          }).catch(()=>{if(imageRef.current===image)setImageState('failed');});
        }} onError={()=>setImageState('failed')}/>}
      {overlay}
    </div>
    </div>
    {previewUrl&&imageState==='loading'&&!showThumbnail&&<Spinner className="fms-preview-spinner" aria-label={t.loading}/>}
    {!previewUrl&&<p className="fms-preview-unavailable fms-muted" role="status">{t.previewUnavailable}</p>}
    {imageState==='failed'&&<p className="fms-preview-error" role="alert">{t.previewFailed}</p>}
      {present&&horizontal&&[[-1,previous],[1,next]].map(([side,item])=>{
        const neighbor=item as Entry|undefined;
        const url=neighbor?.previewUrl??neighbor?.thumbnailUrl;
        return url?<div key={side as number} className="fms-preview-neighbor" style={{'--fms-preview-side':Number(side)} as CSSProperties} aria-hidden="true"><img src={url} alt="" draggable={false}/></div>:null;
      })}
    </Motion.div>
  </Motion.div>;
}

const reducedMotion = ()=>window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function thumbnailFrame(base:DOMRect,target:DOMRect):Keyframe {
  const x = target.x+target.width/2-base.x-base.width/2;
  const y = target.y+target.height/2-base.y-base.height/2;
  const scale = Math.max(target.width/base.width,target.height/base.height);
  const clipX = Math.max(0,(1-target.width/(base.width*scale))*50);
  const clipY = Math.max(0,(1-target.height/(base.height*scale))*50);
  return {transform:`translate(${x}px,${y}px) scale(${scale})`,clipPath:`inset(${clipY}% ${clipX}% round ${6/scale}px)`};
}
