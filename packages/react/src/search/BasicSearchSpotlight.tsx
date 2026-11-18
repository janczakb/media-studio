import {useShortcutRegistry} from '../shortcuts/context';
import {Button,Modal,SearchField} from '@heroui/react';
import {XIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef} from 'react';
import {Icon,type Messages} from '../ui';

/** Legacy transports keep their existing list-search semantics in the same overlay. */
export function BasicSearchSpotlight({open,onOpenChange,value,onChange,portal,t}:{open:boolean;onOpenChange:(open:boolean)=>void;value:string;onChange:(value:string)=>void;portal:HTMLDivElement;t:Messages}){
  const input=useRef<HTMLInputElement>(null);
  const shortcutRegistry=useShortcutRegistry();
  useEffect(()=>{
    if(shortcutRegistry)return;
    const doc=portal.ownerDocument;
    const shortcut=(event:KeyboardEvent)=>{
      if(event.defaultPrevented||event.isComposing||event.ctrlKey||event.metaKey||event.altKey||event.key.toLowerCase()!=='s')return;
      const path=event.composedPath();
      if(path.some(node=>node instanceof Element&&node.matches('input,textarea,select,[contenteditable="true"],[role="dialog"]')))return;
      if(!path.includes(portal)&&event.target!==doc.body)return;
      event.preventDefault();onOpenChange(true);
    };
    doc.addEventListener('keydown',shortcut);
    return()=>doc.removeEventListener('keydown',shortcut);
  },[portal,onOpenChange,shortcutRegistry]);
  useEffect(()=>{if(!open)return;const frame=requestAnimationFrame(()=>input.current?.focus());return()=>cancelAnimationFrame(frame);},[open]);
  return <Modal><Modal.Backdrop isOpen={open} isDismissable onOpenChange={onOpenChange} UNSTABLE_portalContainer={portal} className="fms-spotlight-backdrop"><Modal.Container className="fms-spotlight-container" placement="center"><Modal.Dialog className="fms-spotlight-dialog" aria-label={t.searchLibrary}>
    <Modal.Heading className="sr-only">{t.searchLibrary}</Modal.Heading>
    <SearchField className="fms-basic-spotlight" aria-label={t.searchHint} value={value} onChange={onChange}><SearchField.Group><SearchField.SearchIcon/><SearchField.Input ref={input} placeholder={t.search} onKeyDown={event=>{if(event.key==='Enter')onOpenChange(false);}}/><SearchField.ClearButton aria-label={t.clearSearch}/><Button isIconOnly variant="ghost" aria-label={t.close} onPress={()=>onOpenChange(false)}><Icon icon={XIcon}/></Button></SearchField.Group></SearchField>
  </Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>;
}
