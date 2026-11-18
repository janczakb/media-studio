import {Button,Input,Label,Modal,Spinner,Tabs,TextField} from '@heroui/react';
import {XIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport,Workspace} from '../../contracts';
import {Icon} from '../../ui';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {WorkspacePeoplePicker,type WorkspaceCandidate} from '../layout/WorkspacePeoplePicker';
import {MemberRoleSelect} from './MemberRoleSelect';

export function AddPeopleDialog({workspace,transport,portal,onClose,onChanged}:{workspace:Workspace;transport:StudioTransport;portal:HTMLElement;onClose:()=>void;onChanged:()=>void}){
 const {tr}=useWorkspaceMessages();
 const canAdd=!!transport.memberCandidates&&!!transport.assignMember;
 const [mode,setMode]=useState(canAdd?'existing':'email'),[people,setPeople]=useState<WorkspaceCandidate[]>([]);
 const [email,setEmail]=useState(''),[role,setRole]=useState('viewer'),[overlay,setOverlay]=useState<HTMLDivElement|null>(null);
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const request=useRef<AbortController|null>(null);
 useEffect(()=>()=>request.current?.abort(),[]);
 const submit=async()=>{
  if(request.current)return;
  const c=new AbortController();request.current=c;setBusy(true);setError('');setNotice('');
  try{
   if(mode==='email'){await transport.inviteMember!(workspace.id,{email:email.trim(),role},c.signal);if(!c.signal.aborted)setEmail('');}
   else{for(const person of people){await transport.assignMember!(workspace.id,{id:person.id,type:person.type,role:person.role},c.signal);if(c.signal.aborted)return;setPeople(current=>current.filter(item=>item.id!==person.id||item.type!==person.type));onChanged();}}
   if(!c.signal.aborted){onChanged();setNotice(tr('accessChangesSaved'));}
  }catch(reason){if(!c.signal.aborted)setError(reason instanceof Error?reason.message:String(reason));}
  finally{request.current=null;if(!c.signal.aborted)setBusy(false);}
 };
 return <Modal><Modal.Backdrop className="fms-people-backdrop" isOpen isDismissable={!busy} isKeyboardDismissDisabled={busy} onOpenChange={open=>{if(!open&&!busy)onClose();}} UNSTABLE_portalContainer={portal}><Modal.Container size="md" placement="center" className="fms-share-container"><Modal.Dialog aria-label={tr('addPeople')} className="fms-share-dialog fms-add-people-dialog"><Modal.Header className="fms-share-head"><Modal.Heading>{tr('addPeople')}</Modal.Heading><Button isIconOnly variant="ghost" aria-label={tr('close')} isDisabled={busy} onPress={onClose}><Icon icon={XIcon} size={24}/></Button></Modal.Header><Modal.Body className="fms-share-body">
 <p className="fms-add-people-description">{tr('teammatesDescription')}</p>
 <Tabs selectedKey={mode} onSelectionChange={key=>{setMode(String(key));setError('');setNotice('');}}><Tabs.ListContainer><Tabs.List aria-label={tr('addPeople')}>{canAdd&&<Tabs.Tab id="existing" isDisabled={busy}>{tr('existingUsersTab')}<Tabs.Indicator/></Tabs.Tab>}{transport.inviteMember&&<Tabs.Tab id="email" isDisabled={busy}>{tr('inviteByEmail')}<Tabs.Indicator/></Tabs.Tab>}</Tabs.List></Tabs.ListContainer>
 {canAdd&&<Tabs.Panel id="existing"><div className="fms-add-people-content"><WorkspacePeoplePicker candidateSource="members" workspace={workspace.id} transport={transport} value={people} onChange={setPeople} portal={overlay??portal} disabled={busy}/>{people.map(person=><div className="fms-add-person-role" key={person.type+person.id}><span>{person.name??person.email}</span><MemberRoleSelect value={person.role} onChange={next=>setPeople(current=>current.map(item=>item.id===person.id&&item.type===person.type?{...item,role:next}:item))} owner={workspace.role==='owner'} disabled={busy} portal={overlay??portal}/></div>)}</div></Tabs.Panel>}
 {transport.inviteMember&&<Tabs.Panel id="email"><form id="fms-invite-person" className="fms-add-people-content" onSubmit={event=>{event.preventDefault();void submit();}}><div className="fms-add-email-row"><TextField className="fms-add-email" value={email} onChange={setEmail} isRequired isDisabled={busy}><Label>{tr('emailAddress')}</Label><Input type="email" variant="secondary" autoComplete="email"/></TextField><MemberRoleSelect value={role} onChange={setRole} owner={workspace.role==='owner'} disabled={busy} portal={overlay??portal}/></div></form></Tabs.Panel>}
 </Tabs>{error&&<p role="alert" className="fms-error">{error}</p>}{notice&&<p role="status">{notice}</p>}
 </Modal.Body><Modal.Footer><Button variant="secondary" isDisabled={busy} onPress={onClose}>{tr('close')}</Button><Button type={mode==='email'?'submit':'button'} {...(mode==='email'?{form:'fms-invite-person'}:{})} isDisabled={busy||(mode==='existing'?people.length===0:!email.trim())} {...(mode==='existing'?{onPress:()=>void submit()}:{})}>{busy&&<Spinner size="sm"/>}{tr(mode==='email'?'sendInvitation':'addSelectedPeople')}</Button></Modal.Footer><div ref={setOverlay}/></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>;
}
