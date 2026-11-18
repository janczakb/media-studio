import {Button,Dropdown,Input,Label,Modal,TextField} from '@heroui/react';
import {MoreHorizontalIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport,Workspace} from '../../contracts';
import {Icon} from '../../ui';

export function WorkspaceMenu({workspace,transport,portal,onSaved,pl}:{workspace:Workspace;transport:StudioTransport;portal:HTMLElement;onSaved:(workspace:Workspace)=>void;pl:boolean}){
 const [rename,setRename]=useState(false),[name,setName]=useState(workspace.name),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const request=useRef<AbortController|null>(null);
 useEffect(()=>()=>request.current?.abort(),[]);
 if(workspace.role!=='owner'||workspace.permissions?.['workspaces.manage']!==true||!transport.updateWorkspace||!transport.makeDefaultWorkspace)return null;
 const save=async(makeDefault=false)=>{
  if(busy||(!makeDefault&&!name.trim()))return;
  const controller=new AbortController();request.current=controller;setBusy(true);setError('');
  try{const updated=makeDefault?await transport.makeDefaultWorkspace!(workspace.id,controller.signal):await transport.updateWorkspace!(workspace.id,name.trim(),controller.signal);if(!controller.signal.aborted){onSaved(updated);setRename(false);}}
  catch(reason){if(!controller.signal.aborted)setError(reason instanceof Error?reason.message:'Error');}
  finally{if(!controller.signal.aborted)setBusy(false);}
 };
 return <><Dropdown><Button size="sm" variant="ghost" isIconOnly isDisabled={busy} aria-label={`${pl?'Opcje przestrzeni':'Workspace options'}: ${workspace.name}`}><Icon icon={MoreHorizontalIcon} size={18}/></Button><Dropdown.Popover UNSTABLE_portalContainer={portal} placement="bottom end"><Dropdown.Menu onAction={key=>{if(key==='rename'){setName(workspace.name);setRename(true);}if(key==='default')void save(true);}}><Dropdown.Item id="rename"><Label>{pl?'Zmień nazwę':'Rename'}</Label></Dropdown.Item>{!workspace.isDefault&&workspace.workspaceType!=='personal'&&<Dropdown.Item id="default"><Label>{pl?'Ustaw jako domyślną':'Set as default'}</Label></Dropdown.Item>}</Dropdown.Menu></Dropdown.Popover></Dropdown>
 {rename&&<Modal><Modal.Backdrop isOpen isDismissable={!busy} isKeyboardDismissDisabled={busy} onOpenChange={open=>{if(!open&&!busy)setRename(false);}} UNSTABLE_portalContainer={portal}><Modal.Container size="sm" placement="center"><Modal.Dialog><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{pl?'Nazwa przestrzeni':'Workspace name'}</Modal.Heading></Modal.Header><Modal.Body><TextField value={name} onChange={setName} isDisabled={busy}><Label>{pl?'Nazwa':'Name'}</Label><Input variant="secondary" maxLength={160}/></TextField>{error&&<p role="alert">{error}</p>}</Modal.Body><Modal.Footer><Button isDisabled={busy||!name.trim()} onPress={()=>void save()}>{pl?'Zapisz':'Save'}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>}
 {!rename&&error&&<span role="alert" className="fms-error">{error}</span>}</>;
}
