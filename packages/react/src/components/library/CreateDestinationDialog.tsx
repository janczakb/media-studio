import {Button,Input,Modal,Spinner} from '@heroui/react';
import {Folder01Icon,XIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport} from '../../contracts';
import {Icon,errorText,type Messages} from '../../ui';

/** A nested overlay keeps the destination tree and its selection mounted underneath. */
export function CreateDestinationDialog({workspace,transport,portal,t,parent,path,onClose,onCreated}:{
  workspace:string;transport:StudioTransport;portal:HTMLDivElement;t:Messages;
  parent:string|null;path:readonly string[];onClose:()=>void;onCreated:()=>void|Promise<void>;
}) {
  const [name,setName]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const request=useRef<AbortController|null>(null);
  const modifier=typeof navigator!=='undefined'&&/Mac|iPhone|iPad/.test(navigator.platform)?'⌘':'Ctrl';
  useEffect(()=>()=>request.current?.abort(),[]);
  const save=()=>{
    if(request.current||!name.trim())return;
    const controller=new AbortController();request.current=controller;setBusy(true);setError('');
    void transport.createFolder(workspace,parent,name.trim(),controller.signal).then(async()=>{
      if(!controller.signal.aborted)await onCreated();
    }).catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{
      request.current=null;if(!controller.signal.aborted)setBusy(false);
    });
  };
  return <Modal><Modal.Backdrop isOpen isDismissable={!busy} onOpenChange={open=>{if(!open&&!request.current)onClose();}} UNSTABLE_portalContainer={portal} className="fms-create-destination-backdrop">
    <Modal.Container className="fms-create-destination-container" placement="center" size="md">
      <Modal.Dialog className="fms-create-destination-dialog" aria-label={t.createFolderTitle}>
        <form onSubmit={event=>{event.preventDefault();save();}} onKeyDown={event=>{
          event.stopPropagation();
          if(event.key==='Escape'){event.preventDefault();if(!request.current)onClose();return;}
          if(event.key==='Enter'&&(event.metaKey||event.ctrlKey)){event.preventDefault();save();}
        }}>
          <Modal.Header className="fms-create-destination-header"><Modal.Heading>{t.createFolderTitle}</Modal.Heading>
            <Button isIconOnly variant="tertiary" aria-label={t.close} isDisabled={busy} onPress={onClose}><Icon icon={XIcon} size={24}/></Button>
          </Modal.Header>
          <Modal.Body className="fms-create-destination-body"><Input aria-label={t.name} autoFocus placeholder={t.folderNamePlaceholder} value={name} onChange={event=>setName(event.target.value)} maxLength={255} disabled={busy}/>
            {error&&<p className="fms-error" role="alert">{error}</p>}
          </Modal.Body>
          <Modal.Footer className="fms-create-destination-footer">
            <Button variant="tertiary" isDisabled={busy} onPress={onClose}>{t.cancel}<kbd>ESC</kbd></Button>
            <Button type="submit" isDisabled={busy||!name.trim()}>{busy?<Spinner size="sm"/>:t.saveFolder}<span className="fms-create-shortcut" aria-hidden="true"><kbd>{modifier}</kbd><span>+</span><kbd>↵</kbd></span></Button>
          </Modal.Footer>
        </form>
        <div className="fms-create-destination-path" title={[t.destinationSpaces,...path].join(' / ')}><div className="fms-create-path-segments">{[t.destinationSpaces,...path].map((segment,index)=><span className="fms-create-path-segment" key={index}>
          {index>0&&<span className="fms-create-path-separator" aria-hidden="true">/</span>}{path.length>0&&index===path.length&&<Icon icon={Folder01Icon} size={18}/>}<span className="fms-create-path-name" title={segment}>{segment}</span>
        </span>)}</div></div>
      </Modal.Dialog>
    </Modal.Container>
  </Modal.Backdrop></Modal>;
}
