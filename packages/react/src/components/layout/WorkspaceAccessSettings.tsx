import {Button,Spinner} from '@heroui/react';
import {useEffect,useRef,useState} from 'react';
import type {StudioTransport,Workspace,WorkspaceMember} from '../../contracts';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {errorText} from '../../ui';
import {InvitationsTable} from '../management/InvitationsTable';
import {AddPeopleDialog} from '../management/AddPeopleDialog';
import {MembersTable} from '../members/MembersTable';

export function WorkspaceAccessSettings({workspace,transport,portal}:{workspace:Workspace;transport:StudioTransport;portal:HTMLElement}){
 const {tr}=useWorkspaceMessages();
 const [revision,setRevision]=useState(0),[busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const [adding,setAdding]=useState(false),[remove,setRemove]=useState<WorkspaceMember|null>(null);
 const active=useRef(false),request=useRef<AbortController|null>(null);
 useEffect(()=>()=>request.current?.abort(),[]);
 const invite=workspace.permissions?.['members.invite']===true;
 async function run(action:(signal:AbortSignal)=>Promise<void>){if(active.current)return;active.current=true;const c=new AbortController();request.current=c;setBusy(true);setError('');setNotice('');try{await action(c.signal);if(!c.signal.aborted){setRevision(v=>v+1);setNotice(tr('accessChangesSaved'));}}catch(e){if(!c.signal.aborted)setError(errorText(e));}finally{active.current=false;if(!c.signal.aborted)setBusy(false);}}
 return <section className="fms-workspace-access">
 <div className="fms-workspace-access-title"><div><h3>{tr('peopleWithAccess')}</h3><p>{tr('workspaceAccessHint')}</p></div>{invite&&(transport.assignMember||transport.inviteMember)&&<Button size="sm" variant="secondary" isDisabled={busy} onPress={()=>setAdding(true)}>{tr('addPeople')}</Button>}</div>
 {adding&&<AddPeopleDialog workspace={workspace} transport={transport} portal={portal} onClose={()=>setAdding(false)} onChanged={()=>setRevision(value=>value+1)}/>}
 {busy&&<Spinner size="sm"/>}{notice&&<p role="status">{notice}</p>}{error&&<p className="fms-error" role="alert">{error}</p>}
 {transport.members?<MembersTable workspace={workspace} transport={transport} portal={portal} pl={false} revision={revision} busy={busy} onRole={(person,value)=>void run(async signal=>{await transport.updateMember!(workspace.id,person.id,value,signal);})} onRemove={setRemove}/>:<p>{tr('workspaceAccessUnavailable')}</p>}
 {remove&&<div className="fms-workspace-remove-access" role="group" aria-label={tr('removeAccess')}><p>{tr('removePersonAccess',{name:remove.name??remove.email??tr('member')})}</p><Button size="sm" variant="secondary" isDisabled={busy} onPress={()=>setRemove(null)}>{tr('cancel')}</Button><Button size="sm" variant="danger" isDisabled={busy} onPress={()=>void run(async signal=>{await transport.removeMember!(workspace.id,remove.id,signal);if(!signal.aborted)setRemove(null);})}>{tr('removeAccess')}</Button></div>}
 {invite&&transport.invitations&&<section className="fms-workspace-invitation-table"><div className="fms-workspace-access-title"><h3>{tr('teamInvitations')}</h3></div><InvitationsTable key={workspace.id+':'+revision} workspace={workspace} transport={transport} busy={busy} onRevoke={id=>void run(async signal=>{await transport.revokeInvitation!(workspace.id,id,signal);})}/></section>}
 </section>;
}
