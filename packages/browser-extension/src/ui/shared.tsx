import {Button,Input,Label,ListBox,Select,Switch,TextField} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import type {ComponentProps,ReactNode} from 'react';
import {useEffect,useState} from 'react';
import {send} from '../core/webextension';
import type {Snapshot} from '../core/types';
import en from '../locales/en';import pl from '../locales/pl';
export const words=(locale:string)=>locale==='pl'?pl:en;
export function Field({label,value,onChange,type='text',placeholder}:{label:string;value:string;onChange:(v:string)=>void;type?:'text'|'number'|'url';placeholder?:string}){return <TextField><Label>{label}</Label><Input variant="secondary" type={type} value={value} placeholder={placeholder} onChange={e=>onChange(e.target.value)}/></TextField>;}
export function Choice({label,value,items,onChange,portalContainer}:{label:string;value:string;items:{id:string;name:string}[];onChange:(v:string)=>void;portalContainer?:Element}){return <Select aria-label={label} value={value||null} onChange={v=>onChange(String(v??''))}><Label>{label}</Label><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger><Select.Popover UNSTABLE_portalContainer={portalContainer}><ListBox>{items.map(i=><ListBox.Item key={i.id} id={i.id} textValue={i.name}><Label>{i.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover></Select>;}
export function Toggle({label,value,onChange}:{label:string;value:boolean;onChange:(v:boolean)=>void}){return <Switch isSelected={value} onChange={onChange}><Switch.Control><Switch.Thumb/></Switch.Control><Switch.Content><Label>{label}</Label></Switch.Content></Switch>;}
export function Icon({icon,size=20}:{icon:ComponentProps<typeof HugeiconsIcon>['icon'];size?:number}){return <HugeiconsIcon icon={icon} size={size} strokeWidth={1.5}/>;}
export function useSnapshot(){const [snapshot,setSnapshot]=useState<Snapshot|null>(null),[error,setError]=useState('');useEffect(()=>{let alive=true,ready=false,timer:ReturnType<typeof setTimeout>;const refresh=async()=>{try{const next=await send<Snapshot>('snapshot');if(alive){setSnapshot(next);if(!ready)setError('');ready=true;}}catch(e){if(alive)setError(e instanceof Error?e.message:'Extension unavailable.');}finally{if(alive)timer=setTimeout(()=>void refresh(),1500);}};void refresh();return()=>{alive=false;clearTimeout(timer);};},[]);return {snapshot,error,setError};}
