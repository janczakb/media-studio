import {createPortal} from 'react-dom';
import type {EmojiToneControl} from '../../workspace-emoji';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {Button,Popover,Spinner,Dropdown,Label} from '@heroui/react';
import {useCallback,useEffect,useId,useRef,useState} from 'react';
import {Folder01Icon,HandIcon} from '@hugeicons/core-free-icons';
import {Icon} from '../../ui';
import {usePopoverDismiss} from '../../usePopoverDismiss';

type Module=typeof import('../../workspace-emoji');
let pending:Promise<Module>|undefined;
function load(){return pending??=import(new URL('./workspace-emoji.js',import.meta.url).href).catch(error=>{pending=undefined;throw error;});}
export function WorkspaceMark({emoji,size=22}:{emoji?:string|null|undefined;size?:number}) {
 return emoji?<span className="fms-workspace-emoji" aria-hidden="true" style={{fontSize:size}}>{emoji}</span>:<Icon icon={Folder01Icon} size={size}/>;
}
const tones=[
 {id:'neutral',label:'skinNeutral',color:'#f3c64b'},
 {id:'1f3fb',label:'skinLight',color:'#f4d8c3'},
 {id:'1f3fc',label:'skinMediumLight',color:'#dfb18c'},
 {id:'1f3fd',label:'skinMedium',color:'#bd8b66'},
 {id:'1f3fe',label:'skinMediumDark',color:'#926244'},
 {id:'1f3ff',label:'skinDark',color:'#604336'},
] as const;
function SkinToneSelect({control,portal}:{control:EmojiToneControl;portal:HTMLElement}) {
 const {messages}=useWorkspaceMessages();
 const [open,setOpen]=useState(false),id=useId();const close=useCallback(()=>setOpen(false),[]);usePopoverDismiss(portal,open,close,id);
 const selected=tones.find(item=>item.id===control.value)??tones[0];
 return <Dropdown isOpen={open} onOpenChange={setOpen}>
  <Button data-fms-popover={id} className="fms-emoji-tone-trigger" variant="secondary" isIconOnly aria-label={`${messages.emojiSkinTone}: ${messages[selected.label]}`}><Icon icon={HandIcon} size={20} strokeWidth={1.75}/><span className="fms-emoji-tone-dot" style={{backgroundColor:selected.color}} aria-hidden="true"/></Button>
  <Dropdown.Popover data-fms-popover={id} className="fms-emoji-tone-menu" placement="bottom end" UNSTABLE_portalContainer={portal}>
   <Dropdown.Menu aria-label={messages.emojiSkinTone} selectionMode="single" selectedKeys={[control.value]} onAction={key=>{const selected=tones.find(item=>item.id===key);if(selected){control.onChange(selected.id);close();}}}>
    {tones.map(item=><Dropdown.Item key={item.id} id={item.id} textValue={messages[item.label]}><span className="fms-emoji-tone-swatch" style={{backgroundColor:item.color}} aria-hidden="true"/><Label>{messages[item.label]}</Label><Dropdown.ItemIndicator/></Dropdown.Item>)}
   </Dropdown.Menu>
  </Dropdown.Popover>
 </Dropdown>;
}
function EmojiGrid({onSelect}:{onSelect:(emoji:string)=>void}) {
 const {locale,fallbackLocale,messages,tr}=useWorkspaceMessages();
 const [toneControl,setToneControl]=useState<EmojiToneControl|null>(null);
 const host=useRef<HTMLDivElement>(null),callback=useRef(onSelect);callback.current=onSelect;
 const [failure,setFailure]=useState(false),[ready,setReady]=useState(false),[attempt,setAttempt]=useState(0);
 useEffect(()=>{let disposed=false;let mounted:ReturnType<Module['mountWorkspaceEmoji']>|undefined;setFailure(false);setReady(false);void load().then(module=>{if(disposed||!host.current)return;mounted=module.mountWorkspaceEmoji(host.current,{locale,fallbackLocale,messages,onToneControl:setToneControl,onSelect:(e:string)=>callback.current(e)});setReady(true);}).catch(()=>{if(!disposed)setFailure(true);});return()=>{disposed=true;mounted?.destroy();};},[locale,fallbackLocale,messages,attempt]);
 return <div className="fms-workspace-emoji-grid"><div ref={host}/>{toneControl&&host.current&&createPortal(<SkinToneSelect control={toneControl} portal={host.current}/>,toneControl.element)}{!ready&&!failure&&<Spinner size="sm"/>}{failure&&<Button variant="secondary" onPress={()=>setAttempt(v=>v+1)}>{tr('tryAgain')}</Button>}</div>;
}
export function WorkspaceEmojiPicker({value,onChange,portal,disabled}:{value:string|null;onChange:(emoji:string|null)=>void;portal:HTMLElement;disabled?:boolean}) {
 const {tr}=useWorkspaceMessages();
 const [open,setOpen]=useState(false);const id=useId();const close=useCallback(()=>setOpen(false),[]);usePopoverDismiss(portal,open,close,id,'.fms-emoji-tone-menu');
 return <Popover isOpen={open} onOpenChange={setOpen}><Button className="fms-workspace-icon-trigger" data-fms-popover={id} isIconOnly variant="secondary" isDisabled={disabled??false} aria-label={tr('chooseWorkspaceIcon')}><WorkspaceMark emoji={value} size={32}/></Button><Popover.Content shouldCloseOnInteractOutside={()=>false} data-fms-popover={id} className="fms-workspace-emoji-popover" placement="bottom start" UNSTABLE_portalContainer={portal}><Popover.Dialog aria-label={tr('workspaceIcon')}><EmojiGrid onSelect={emoji=>{onChange(emoji);close();}}/><Button variant="ghost" size="sm" className="fms-emoji-reset" onPress={()=>{onChange(null);close();}}><WorkspaceMark size={18}/>{tr('defaultFolderIcon')}</Button></Popover.Dialog></Popover.Content></Popover>;
}
