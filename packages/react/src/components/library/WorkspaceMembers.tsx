import {Avatar,Button,Modal,Spinner} from '@heroui/react';
import {MoreHorizontalIcon,XIcon} from '@hugeicons/core-free-icons';
import {useEffect,useState} from 'react';
import type {StudioTransport,Workspace,WorkspaceMember} from '../../contracts';
import {Icon} from '../../ui';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {WorkspaceAccessSettings} from '../layout/WorkspaceAccessSettings';

type Props={workspace:Workspace;transport:StudioTransport;portal:HTMLElement;locale:string};
export function WorkspaceMembers({workspace,transport,portal}:Props){
 const {tr}=useWorkspaceMessages();
 const [items,setItems]=useState<WorkspaceMember[]>([]);
 const [busy,setBusy]=useState(true),[open,setOpen]=useState(false),[revision,setRevision]=useState(0);
 const [overlay,setOverlay]=useState<HTMLDivElement|null>(null);
 useEffect(()=>{
  const c=new AbortController();setBusy(true);
  if(!transport.members){setBusy(false);return()=>c.abort();}
  void transport.members(workspace.id,null,c.signal).then(page=>{if(!c.signal.aborted){setItems(page.items.slice(0,3));}}).catch(()=>{if(!c.signal.aborted){setItems([]);}}).finally(()=>{if(!c.signal.aborted)setBusy(false);});
  return()=>c.abort();
 },[workspace.id,transport,revision]);
 if(!transport.members)return null;
 const close=()=>{setOpen(false);setRevision(value=>value+1);};
 return <>
 <Button variant="ghost" className="fms-member-stack" aria-label={tr('workspaceMembersTitle')} onPress={()=>setOpen(true)}>
 {items.map(person=><Avatar size="sm" className="fms-member-avatar" key={person.id}>{person.avatarUrl&&<Avatar.Image src={person.avatarUrl} alt=""/>}<Avatar.Fallback>{(person.name??person.email??'?').trim().slice(0,1).toLocaleUpperCase()}</Avatar.Fallback></Avatar>)}
 {busy&&items.length===0?<Spinner size="sm"/>:<span className="fms-member-extra" aria-hidden="true"><Icon icon={MoreHorizontalIcon} size={18}/></span>}
 </Button>
 {open&&<Modal><Modal.Backdrop isOpen onOpenChange={value=>{if(!value)close();}} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="lg" className="fms-workspace-members-container"><Modal.Dialog aria-label={tr('workspaceMembersTitle')} className="fms-workspace-members-dialog"><Modal.Header className="fms-share-head"><div><Modal.Heading>{tr('workspaceMembersTitle')}</Modal.Heading><p className="fms-muted">{workspace.name}</p></div><Button isIconOnly variant="secondary" aria-label={tr('close')} onPress={close}><Icon icon={XIcon} size={22}/></Button></Modal.Header><Modal.Body><WorkspaceAccessSettings workspace={workspace} transport={transport} portal={overlay??portal}/></Modal.Body><Modal.Footer><Button variant="secondary" onPress={close}>{tr('close')}</Button></Modal.Footer><div ref={setOverlay}/></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>}
 </>;
}
