import {Button,Modal} from '@heroui/react';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport,Workspace,WorkspaceMember} from '../../contracts';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {MembersTable} from '../members/MembersTable';
import {PageFrame,ManagementTabs} from './shared';
import {AddPeopleDialog} from './AddPeopleDialog';
import {InvitationsTable} from './InvitationsTable';

type Props={workspace:Workspace;transport:StudioTransport;portal:HTMLDivElement;locale:string};
export function MembersPage({workspace,transport,portal,locale}:Props){
 const {tr}=useWorkspaceMessages();
 const [mode,setMode]=useState('members'),[adding,setAdding]=useState(false);
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[refresh,setRefresh]=useState(0);
 const [remove,setRemove]=useState<WorkspaceMember|null>(null);
 const requests=useRef(new Set<AbortController>()),mutation=useRef(false);
 const invite=workspace.permissions?.['members.invite']===true;
 useEffect(()=>()=>{for(const controller of requests.current)controller.abort();},[]);
 const run=async(action:(signal:AbortSignal)=>Promise<unknown>)=>{
  if(mutation.current)return;mutation.current=true;
  const c=new AbortController();requests.current.add(c);setBusy(true);setError('');
  try{await action(c.signal);if(!c.signal.aborted)setRefresh(v=>v+1);}
  catch(e){if(!c.signal.aborted)setError(e instanceof Error?e.message:String(e));}
  finally{requests.current.delete(c);mutation.current=false;if(!c.signal.aborted)setBusy(false);}
 };
 const tabs:readonly (readonly [string,string])[]=invite&&transport.invitations?[['members',tr('membersTitle')],['invitations',tr('invitationsTitle')]]:[['members',tr('membersTitle')]];
 return <div className="fms-members-page"><PageFrame title={tr('membersTitle')} hint={tr('membersHint')} actions={invite&&(transport.assignMember||transport.inviteMember)&&<Button onPress={()=>setAdding(true)}>{tr('addPeople')}</Button>}>
 <div className="fms-management-tabs"><ManagementTabs label={tr('peopleAndAccess')} value={mode} options={tabs} onChange={setMode}/></div>
 {error&&<p role="alert" className="fms-error">{error}</p>}
 {mode==='members'?<MembersTable workspace={workspace} transport={transport} portal={portal} pl={locale==='pl'} revision={refresh} busy={busy} onRole={(person,role)=>void run(signal=>transport.updateMember!(workspace.id,person.id,role,signal))} onRemove={setRemove}/>:<InvitationsTable key={workspace.id+':'+refresh} workspace={workspace} transport={transport} busy={busy} onRevoke={id=>void run(signal=>transport.revokeInvitation!(workspace.id,id,signal))}/>}
 {adding&&<AddPeopleDialog workspace={workspace} transport={transport} portal={portal} onClose={()=>setAdding(false)} onChanged={()=>setRefresh(v=>v+1)}/>}
 {remove&&<Modal><Modal.Backdrop isOpen isDismissable={!busy} onOpenChange={value=>{if(!value&&!busy)setRemove(null);}} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{tr('removeAccessTitle')}</Modal.Heading></Modal.Header><Modal.Body>{remove.name}</Modal.Body><Modal.Footer><Button variant="secondary" isDisabled={busy} onPress={()=>setRemove(null)}>{tr('cancel')}</Button><Button variant="danger" isDisabled={busy} onPress={()=>void run(async signal=>{await transport.removeMember!(workspace.id,remove.id,signal);if(!signal.aborted)setRemove(null);})}>{tr('removeAccess')}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>}
 </PageFrame></div>;
}
