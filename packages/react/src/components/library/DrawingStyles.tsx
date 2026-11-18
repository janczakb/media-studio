import {Button, Slider, Tooltip} from '@heroui/react';
import {AlignLeftIcon, AlignCenter, AlignRightIcon} from '@hugeicons/core-free-icons';
import {useEffect, useState, type ReactNode} from 'react';
import type {DrawingStyle} from '../../contracts';
import {Icon, type Messages} from '../../ui';

const colors=['#202124','#9ca3af','#d875ef','#a33bc8','#4169e1','#4599ec','#f3ad43','#e66b12','#059669','#49af59','#fb7185','#e52e36'];
const families=['cursive','sans-serif','serif','monospace'] as const;
export function DrawingStyles({tool,style,color,onChange,onColor,t,portal,baseStyle}: {
  baseStyle:{strokeWidth:number;fontSize:number};tool:string;style:DrawingStyle;color:string;onChange:(style:DrawingStyle)=>void;onColor:(color:string)=>void;t:Messages;portal:HTMLDivElement;
}) {
  const [opacity,setOpacity]=useState(style.opacity ?? 1);
  useEffect(()=>setOpacity(style.opacity ?? 1),[style.opacity]);
  const shape=tool==='rectangle'||tool==='ellipse';
  const text=tool==='text';
  const tip=(label:string,child:ReactNode)=><Tooltip delay={300}>{child}<Tooltip.Content placement="right" UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content></Tooltip>;
  const choice=(label:string,active:boolean,onPress:()=>void,content:ReactNode)=>tip(label,<Button isIconOnly size="sm" variant="ghost" aria-label={label} aria-pressed={active} onPress={onPress}>{content}</Button>);
  return <div className="fms-drawing-styles" role="group" aria-label={t.drawingStyles}>
    <div className="fms-drawing-style-colors" role="toolbar" aria-label={t.drawingColor}>{colors.map(value=><span key={value}>{choice(value,color===value,()=>onColor(value),<span className="fms-drawing-swatch" style={{background:value}}/>)}</span>)}</div>
    <Slider aria-label={t.drawingOpacity} minValue={0} maxValue={100} step={1} value={Math.round(opacity*100)} onChange={value=>setOpacity(Number(value)/100)} onChangeEnd={value=>onChange({opacity:Number(value)/100})}>
      <Slider.Track><Slider.Fill/><Slider.Thumb/></Slider.Track>
    </Slider>
    {tool!=='text' && <>
      {(shape||tool==='pen')&&<div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingFill}>{(['none','semi','solid','pattern'] as const).map((fill,index)=><span key={fill}>{choice(t.drawingFills.split('|')[index]!, (style.fill??'none')===fill,()=>onChange({fill}),<span className={`fms-drawing-fill-sample is-${fill}`}/>)}</span>)}</div>}
      <div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingDash}>{(['solid','dashed','dotted'] as const).map((dash,index)=><span key={dash}>{choice(t.drawingDashes.split('|')[index]!, (style.dash??'solid')===dash,()=>onChange({dash}),<span className={`fms-drawing-dash-sample is-${dash}`}/>)}</span>)}</div>
    </>}
    <div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingSize}>{[1,3,5,8].map((weight,index)=><span key={weight}>{choice(['S','M','L','XL'][index]!,text?Math.abs((style.fontSize??baseStyle.fontSize)-baseStyle.fontSize*[.75,1,1.5,2][index]!)<.01:Math.abs((style.strokeWidth??baseStyle.strokeWidth)-Math.max(1,Math.min(12,weight*baseStyle.strokeWidth/3)))<.01,()=>onChange(tool==='text'?{fontSize:baseStyle.fontSize*[.75,1,1.5,2][index]!}:{strokeWidth:Math.max(1,Math.min(12,weight*baseStyle.strokeWidth/3))}),<strong>{['S','M','L','XL'][index]}</strong>)}</span>)}</div>
    {text&&<>
      <div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingFont}>{families.map((fontFamily,index)=><span key={fontFamily}>{choice(t.drawingFonts.split('|')[index]!, (style.fontFamily??'sans-serif')===fontFamily,()=>onChange({fontFamily}),<span style={{fontFamily,fontSize:17}}>Aa</span>)}</span>)}</div>
      <div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingAlign}>{(['start','middle','end'] as const).map((textAlign,index)=><span key={textAlign}>{choice(t.drawingAlignments.split('|')[index]!, (style.textAlign??'start')===textAlign,()=>onChange({textAlign}),<Icon icon={[AlignLeftIcon,AlignCenter,AlignRightIcon][index]!} size={22}/>)}</span>)}</div>
      {shape&&<div className="fms-drawing-style-row" role="toolbar" aria-label={t.drawingVerticalAlign}>{(['start','middle','end'] as const).map((verticalAlign,index)=><span key={verticalAlign}>{choice(t.drawingVerticalAlignments.split('|')[index]!, (style.verticalAlign??'middle')===verticalAlign,()=>onChange({verticalAlign}),<Icon icon={[AlignLeftIcon,AlignCenter,AlignRightIcon][index]!} size={22}/>)}</span>)}</div>}
    </>}
  </div>;
}
