import {Button,Modal} from '@heroui/react';
import {Add01Icon,Upload04Icon,FolderAddIcon,Note01Icon} from '@hugeicons/core-free-icons';
import {useState} from 'react';
import {Icon,type Messages} from '../../ui';

export function LibraryCreateOverlay({portal,t,upload,folder,note}:{portal:HTMLDivElement;t:Messages;upload:()=>void;folder:()=>void;note?:(()=>void)|undefined}){
 const [open,setOpen]=useState(false);
 const actions=[{id:'upload',label:t.upload,icon:Upload04Icon,run:upload},{id:'folder',label:t.newFolder,icon:FolderAddIcon,run:folder},...(note?[{id:'note',label:t.newNote,icon:Note01Icon,run:note}]:[])];
 return <><Button isIconOnly variant="ghost" aria-label={t.create} aria-haspopup="dialog" aria-expanded={open} onPress={()=>setOpen(true)}><Icon icon={Add01Icon} size={24}/></Button>
 <Modal><Modal.Backdrop isOpen={open} onOpenChange={setOpen} UNSTABLE_portalContainer={portal} className="fms-create-overlay-backdrop">
  <Modal.Container placement="center" className="fms-create-overlay-container"><Modal.Dialog aria-label={t.create} className="fms-create-overlay-dialog">
   <Modal.Heading className="fms-create-overlay-heading">{t.create}</Modal.Heading>
   <div className="fms-create-overlay-actions">{actions.map((action,index)=><Button key={action.id} variant="ghost" aria-label={action.label} className="fms-create-overlay-action" style={{'--fms-create-index':index} as React.CSSProperties} onPress={()=>{setOpen(false);action.run();}}><span className="fms-create-overlay-icon"><Icon icon={action.icon} size={24}/></span><span>{action.label}</span></Button>)}</div>
  </Modal.Dialog></Modal.Container>
 </Modal.Backdrop></Modal></>;
}
