import {DrawingToolDock,type DrawingTool} from './DrawingToolDock';
import {drawingDefaults} from './drawingDefaults';
import {DrawingComments,type DrawingCommentContext} from './DrawingComments';
import {Button} from '@heroui/react';
import {ColorsIcon} from '@hugeicons/core-free-icons';
import {memo, useEffect, useId, useRef, useState, type PointerEvent} from 'react';
import type {DrawingMark, DrawingPoint, DrawingStyle} from '../../contracts';
import {Icon, type Messages} from '../../ui';
import {DrawingStyles} from './DrawingStyles';
import {drawingBounds, moveDrawing, resizeDrawing} from './drawingGeometry';

type Tool=DrawingTool;
const colors = ['#4169e1'];

function pathFor({tool, points}: DrawingMark): string {
  const [x, y] = points[0]!;
  const [endX, endY] = points.at(-1)!;
  if (tool === 'rectangle') return `M${x} ${y}H${endX}V${endY}H${x}Z`;
  if (tool === 'ellipse') {
    const rx = Math.abs(endX - x) / 2;
    const ry = Math.abs(endY - y) / 2;
    const left = Math.min(x, endX);
    const middle = (y + endY) / 2;
    return `M${left} ${middle}a${rx} ${ry} 0 1 0 ${2 * rx} 0a${rx} ${ry} 0 1 0 ${-2 * rx} 0`;
  }
  if (tool === 'arrow') {
    const angle = Math.atan2(endY - y, endX - x);
    const arm = Math.min(25, Math.hypot(endX - x, endY - y) * .4);
    const wing = (offset: number) => `${endX - Math.cos(angle + offset) * arm} ${endY - Math.sin(angle + offset) * arm}`;
    return `M${x} ${y}L${endX} ${endY}M${wing(-.55)}L${endX} ${endY}L${wing(.55)}`;
  }
  return points.length === 1 ? `M${x} ${y}h.01` : 'M' + points.map(point => point.join(' ')).join('L');
}

export const ImageDrawingOverlay = memo(function ImageDrawingOverlay({marks}: {marks: DrawingMark[]}) {
  return <svg className="fms-drawing-overlay" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">
    <Marks marks={marks}/>
  </svg>;
});

function textPosition(mark: DrawingMark): DrawingPoint {
  if(mark.tool!=='rectangle'&&mark.tool!=='ellipse')return mark.points[0]!;
  const box=drawingBounds(mark),align=mark.textAlign??'start';
  return [box.x+(align==='middle'?box.width/2:align==='end'?box.width-8:8),box.y+(mark.verticalAlign==='start'?8:mark.verticalAlign==='end'?box.height-8:box.height/2)];
}

const Marks = memo(function Marks({marks, selected, editing}: {marks: DrawingMark[]; selected?: number | null; editing?: number | undefined}) {
  const patternId=useId().replace(/:/g,'');
  return marks.map((mark, index) => {
    const [textX,textY]=textPosition(mark);
    const shape=mark.tool==='rectangle'||mark.tool==='ellipse';
    const anchor=mark.textAlign??'start';
    const size=mark.fontSize??32;
    return <g key={index} data-mark={index} opacity={editing === index ? 0 : (mark.opacity??1)*(selected === index ? .6 : 1)}>
      {mark.fill==='pattern'&&<defs><pattern id={`${patternId}-${index}`} width="12" height="12" patternUnits="userSpaceOnUse"><path d="M0 12L12 0" stroke={mark.color} strokeWidth="2" opacity=".3"/></pattern></defs>}
      {mark.tool !== 'text'&&<path d={pathFor(mark)} stroke={mark.color} strokeWidth={mark.strokeWidth??3} strokeDasharray={mark.dash==='dashed'?'9 6':mark.dash==='dotted'?'1 6':undefined} vectorEffect="non-scaling-stroke" fill={mark.fill==='pattern'?`url(#${patternId}-${index})`:mark.fill==='solid'||mark.fill==='semi'?mark.color:'none'} fillOpacity={mark.fill==='semi'?.18:1} strokeLinecap="round" strokeLinejoin="round"/>}
      {mark.text&&<foreignObject x={textX} y={textY} width={Math.max(1,1000-textX)} height={Math.max(1,1000-textY)} className="fms-drawing-text-object">
        <div className="fms-drawing-text-content" style={{color:mark.color,fontSize:size,fontFamily:mark.fontFamily??'sans-serif',textAlign:anchor==='middle'?'center':anchor==='end'?'right':'left',transform:`scaleY(var(--fms-preview-aspect, 1)) translate(${anchor==='middle'?'-50%':anchor==='end'?'-100%':'0'},${shape&&mark.verticalAlign!=='start'?mark.verticalAlign==='end'?'-100%':'-50%':'0'})`}}>{mark.text}</div>
      </foreignObject>}
    </g>;
  });
});

export function ImageDrawingEditor({initial, t, busy, error, portal, onCancel, onSave,comments}: {
  comments?:DrawingCommentContext|undefined;initial: DrawingMark[]; portal: HTMLDivElement; t: Messages; busy: boolean; error: string; onCancel: () => void; onSave: (marks: DrawingMark[]) => void;
}) {
  const [history, setHistory] = useState({past: [] as DrawingMark[][], marks: initial, future: [] as DrawingMark[][]});
  const [tool, setTool] = useState<Tool>('pen');
  const [color, setColor] = useState(colors[0]!);
  const [textEdit, setTextEdit] = useState<{point: DrawingPoint; value: string; index?: number} | null>(null);
  const [style, setStyle] = useState<DrawingStyle>({});const [defaults,setDefaults]=useState({strokeWidth:3,fontSize:32});const currentStyle={...defaults,...style};const [commentPoint,setCommentPoint]=useState<DrawingPoint|null>(null),[canComment,setCanComment]=useState(false);
  const [palette, setPalette] = useState(false);
  useEffect(()=>{if(tool==='select'||tool==='comment'||tool==='erase')setPalette(false);},[tool]);
  const [selected, setSelected] = useState<number | null>(null);
  const gesture = useRef<{id: number; mark: DrawingMark; bounds: DOMRect} | null>(null);
  const draft = useRef<SVGPathElement>(null);
  const surfaceRef = useRef<SVGSVGElement>(null);
  useEffect(()=>{const node=surfaceRef.current;if(!node)return;const measure=()=>{const bounds=node.getBoundingClientRect();setDefaults(drawingDefaults(bounds.width,bounds.height));};measure();const observer=new ResizeObserver(measure);observer.observe(node);return()=>observer.disconnect();},[]);
  const hitRef = useRef<number>(-1);
  const resizing = useRef<{index:number;corner:number;original:DrawingMark;bounds:DOMRect;next:DrawingMark}|null>(null);
  const moving = useRef<{index:number;start:DrawingPoint;bounds:DOMRect;element:SVGGElement;dx:number;dy:number}|null>(null);


  const commit = (marks: DrawingMark[]) => {
    setHistory(previous => ({past: [...previous.past.slice(-15), previous.marks], marks, future: []}));
    setSelected(null);
  };
  const undo = () => {
    const previous = history.past.at(-1);
    if (previous) setHistory({past: history.past.slice(0, -1), marks: previous, future: [history.marks, ...history.future]});
    setSelected(null);
  };
  const redo = () => {
    const next = history.future[0];
    if (next) setHistory({past: [...history.past, history.marks], marks: next, future: history.future.slice(1)});
    setSelected(null);
  };
  const point = (event: PointerEvent, rect: DOMRect): DrawingPoint => [
    Math.round(Math.max(0, Math.min(1, (event.clientX - rect.left) / Math.max(1, rect.width))) * 1000),
    Math.round(Math.max(0, Math.min(1, (event.clientY - rect.top) / Math.max(1, rect.height))) * 1000),
  ];
  const finishText = () => {
    if (!textEdit) return;
    const value = textEdit.value.trim();
    if (value) {
      const existing=textEdit.index===undefined?undefined:history.marks[textEdit.index];
      const mark: DrawingMark = existing?{...existing,text:value}:{...currentStyle,tool:'text',color,points:[textEdit.point],text:value};
      commit(textEdit.index === undefined ? [...history.marks, mark] : history.marks.map((item,index) => index === textEdit.index ? mark : item));
    }
    setTextEdit(null);
  };
  const textStyle=textEdit?.index===undefined?currentStyle:history.marks[textEdit.index]??currentStyle;
  const editingShape=textEdit?.index!==undefined&&['rectangle','ellipse'].includes(history.marks[textEdit.index]?.tool??'');
  const textShiftX=textStyle.textAlign==='middle'?'-50%':textStyle.textAlign==='end'?'-100%':'0';
  const textShiftY=editingShape&&textStyle.verticalAlign!=='start'?textStyle.verticalAlign==='end'?'-100%':'-50%':'0';
  const remove = (index: number) => commit(history.marks.filter((_, current) => current !== index));

  return <div className="fms-drawing-editor" tabIndex={0} onKeyDown={event=>{
    if(busy || (event.target as Element).closest('textarea,input,[contenteditable=true]'))return;
    if((event.metaKey||event.ctrlKey)&&event.key.toLowerCase()==='z'){event.preventDefault();event.shiftKey?redo():undo();return;}
    if(event.key==='Escape'){event.preventDefault();setSelected(null);setTool('select');return;}
    if((event.key==='Delete'||event.key==='Backspace')&&selected!==null){event.preventDefault();remove(selected);return;}
    if(!event.metaKey&&!event.ctrlKey&&!event.altKey){
      const shortcuts:Record<string,Tool>={v:'select',d:'pen',e:'erase',a:'arrow',t:'text',r:'rectangle',o:'ellipse',l:'line'};
      if(event.key.toLowerCase()==='c'&&canComment){event.preventDefault();setTool('comment');return;}const next=shortcuts[event.key.toLowerCase()];if(next){event.preventDefault();setTool(next);}
    }
  }}>
    <svg ref={surfaceRef} className="fms-drawing-surface" data-tool={tool} viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-label={t.drawOnImage}
      onDoubleClick={event => {
        event.preventDefault();
        const index = Number((event.target as Element).closest('[data-mark]')?.getAttribute('data-mark') ?? hitRef.current);
        const mark = history.marks[index];
        if (mark && ['text','rectangle','ellipse'].includes(mark.tool) && !busy) {setColor(mark.color);setTextEdit({point:textPosition(mark),value:mark.text ?? '',index});setTool('text');}
      }}
      onPointerDown={event => {
        if (busy || event.button !== 0 || gesture.current) return;
        const hit = (event.target as Element).closest('[data-mark]')?.getAttribute('data-mark');
        hitRef.current=hit == null ? -1 : Number(hit);
        (event.currentTarget.parentElement as HTMLElement).focus({preventScroll:true});
        const hitIndex=hit == null?-1:Number(hit);
        const existing=history.marks[hitIndex];
        if(existing?.tool==='text' && (tool==='text'||tool==='select'&&selected===hitIndex)) {
          event.preventDefault();
          setColor(existing.color);
          setTextEdit({point:existing.points[0]!,value:existing.text??'',index:hitIndex});
          setTool('text');
          return;
        }
        if (tool === 'select' || tool === 'erase') {
          if (tool === 'erase' && hit != null) remove(Number(hit));
          else {
            setSelected(hit == null ? null : Number(hit));
            if(hit != null){
              const bounds=event.currentTarget.getBoundingClientRect();
              moving.current={index:Number(hit),start:point(event,bounds),bounds,element:(event.target as Element).closest('[data-mark]') as SVGGElement,dx:0,dy:0};
              event.currentTarget.setPointerCapture(event.pointerId);
            }
          }
          return;
        }
        if(tool==='comment'){event.preventDefault();if(canComment)setCommentPoint(point(event,event.currentTarget.getBoundingClientRect()));return;}
        if (history.marks.length >= 64) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        const start = point(event, bounds);
        if (tool === 'text') {
          event.preventDefault();
          setTextEdit({point:start,value:''});
          return;
        }
        const mark: DrawingMark = {...currentStyle,tool,color,points:[start]};
        gesture.current = {id: event.pointerId, mark, bounds};
        event.currentTarget.setPointerCapture(event.pointerId);
        draft.current?.setAttribute('d', pathFor(mark));
      }}
      onPointerMove={event => {
        const resize=resizing.current;
        if(resize){resize.next=resizeDrawing(resize.original,resize.corner,point(event,resize.bounds));const path=event.currentTarget.querySelector(`[data-mark="${resize.index}"] path`);path?.setAttribute('d',pathFor(resize.next));return;}
        const move=moving.current;
        if(move){const next=point(event,move.bounds);move.dx=next[0]-move.start[0];move.dy=next[1]-move.start[1];move.element.setAttribute('transform',`translate(${move.dx} ${move.dy})`);return;}
        const current = gesture.current;
        if (!current || current.id !== event.pointerId) return;
        const next = point(event, current.bounds);
        const {points} = current.mark;
        if (current.mark.tool === 'pen') {
          const last = points.at(-1)!;
          if (Math.hypot(last[0] - next[0], last[1] - next[1]) < 3) return;
          if (points.length >= 128) {
            // Preserve the full gesture by thinning the existing samples.
            current.mark.points = points.filter((_, index) => index % 2 === 0);
          }
          current.mark.points.push(next);
        } else points[1] = next;
        draft.current?.setAttribute('d', pathFor(current.mark));
      }}
      onPointerUp={event => {
        const resize=resizing.current;
        if(resize){resizing.current=null;commit(history.marks.map((mark,index)=>index===resize.index?resize.next:mark));setSelected(resize.index);return;}
        const move=moving.current;
        if(move){moving.current=null;move.element.removeAttribute('transform');if(move.dx||move.dy)commit(history.marks.map((mark,index)=>index===move.index?moveDrawing(mark,move.dx,move.dy):mark));return;}
        const current = gesture.current;
        if (!current || current.id !== event.pointerId) return;
        gesture.current = null;
        draft.current?.removeAttribute('d');
        commit([...history.marks, current.mark]);
        if(['rectangle','ellipse','arrow','line'].includes(current.mark.tool)){setTool('select');setSelected(history.marks.length);}
      }}
      onLostPointerCapture={() => {resizing.current=null;moving.current?.element.removeAttribute('transform');moving.current=null;gesture.current = null; draft.current?.removeAttribute('d');}}>
      <Marks marks={history.marks} selected={selected} editing={textEdit?.index}/>
      {selected !== null && history.marks[selected]?.tool !== 'text' && (()=>{
        const mark=history.marks[selected]!;const box=drawingBounds(mark);
        return <g className="fms-drawing-selection">
          <rect x={box.x-5} y={box.y-5} width={box.width+10} height={box.height+10} fill="none" stroke="#4169e1" strokeWidth="1" vectorEffect="non-scaling-stroke" pointerEvents="none"/>
          {[[box.x,box.y],[box.x+box.width,box.y],[box.x+box.width,box.y+box.height],[box.x,box.y+box.height]].map(([x,y],corner)=><rect key={corner} data-resize={corner} x={x!-6} y={y!-6} width="12" height="12" fill="white" stroke="#4169e1" strokeWidth="1" vectorEffect="non-scaling-stroke" onPointerDown={event=>{
            event.stopPropagation();if(busy||!surfaceRef.current)return;
            resizing.current={index:selected,corner,original:mark,next:mark,bounds:surfaceRef.current.getBoundingClientRect()};surfaceRef.current.setPointerCapture(event.pointerId);
          }}/>)}</g>;
      })()}
      <path ref={draft} fill="none" stroke={color} strokeWidth={currentStyle.strokeWidth} vectorEffect="non-scaling-stroke" strokeLinecap="round" strokeLinejoin="round" pointerEvents="none"/>
    </svg>
    <div className="fms-drawing-tool-stack">
      <DrawingToolDock value={tool} onChange={next=>{setSelected(null);setTool(next);if(next==='comment'||next==='select')setPalette(false);}} busy={busy} showComments={!!comments} canComment={!!comments&&canComment} t={t} portal={portal}/>
      <Button isIconOnly size="sm" variant="secondary" aria-label={t.drawingStyles} aria-expanded={palette&&tool!=='comment'&&tool!=='erase'&&tool!=='select'} isDisabled={busy||tool==='comment'||tool==='erase'||tool==='select'}
        onPress={() => setPalette(value => !value)}><span style={{color}}><Icon icon={ColorsIcon} size={24} strokeWidth={2.2}/></span></Button>
      {palette && tool!=='erase'&&tool!=='comment'&&tool!=='select' && <DrawingStyles tool={selected===null?tool:history.marks[selected]?.tool??tool} baseStyle={defaults} style={selected===null?currentStyle:history.marks[selected]??currentStyle} color={selected===null?color:history.marks[selected]?.color??color} t={t} portal={portal}
        onColor={value=>{setColor(value);if(selected!==null){const index=selected;commit(history.marks.map((mark,current)=>current===index?{...mark,color:value}:mark));setSelected(index);}}}
        onChange={patch=>{setStyle(previous=>({...previous,...patch}));if(selected!==null){const index=selected;commit(history.marks.map((mark,current)=>current===index?{...mark,...patch}:mark));setSelected(index);}}}/>}

    </div>
    {textEdit && <textarea key={`${textEdit.point.join('-')}-${textEdit.index ?? 'new'}`} autoFocus className="fms-drawing-inline-text"
      aria-label={t.drawingText} maxLength={120} rows={Math.max(1,textEdit.value.split('\n').length)}
      style={{left:`${textEdit.point[0]/10}%`,top:`${textEdit.point[1]/10}%`,color,transform:`translate(${textShiftX},${textShiftY})`,textAlign:textStyle.textAlign==='middle'?'center':textStyle.textAlign==='end'?'right':'left',fontFamily:textStyle.fontFamily??'sans-serif',fontSize:`${(textStyle.fontSize??32)/10}cqw`,maxWidth:`${Math.max(1,100-textEdit.point[0]/10)}%`}}
      value={textEdit.value} onChange={event=>setTextEdit({...textEdit,value:event.currentTarget.value})}
      onBlur={finishText} onKeyDown={event=>{event.stopPropagation();if(event.key==='Escape'){event.preventDefault();setTextEdit(null);}else if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();finishText();}}}/>}
    {comments&&<DrawingComments context={comments} point={commentPoint} onPoint={setCommentPoint} onReady={setCanComment} t={t}/>}
    <div className="fms-drawing-footer">
      {error && <span role="alert">{error}</span>}
      {history.marks.length >= 64 && <span role="status">{t.drawingLimit}</span>}
      <Button size="sm" variant="secondary" isDisabled={busy} onClick={onCancel}>{t.cancel}</Button>
      <Button size="sm" isPending={busy} isDisabled={busy || !history.past.length && !history.future.length} onClick={() => onSave(history.marks)}>{t.saveDrawing}</Button>
    </div>
  </div>;
}
