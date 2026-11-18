import {useWorkspaceMessages} from '../../workspace-i18n';
import type {WorkspaceMessages} from '../../workspace-i18n';
import {Description, Label, ListBox, Select} from '@heroui/react';

export function memberRoleName(role:string,useManagementMessages:WorkspaceMessages):string {return useManagementMessages[('role'+role) as keyof WorkspaceMessages]??role;}
export function MemberRoleSelect({value,onChange,owner,disabled,portal}:{value:string;onChange:(role:string)=>void;owner:boolean;disabled:boolean;portal:HTMLElement;pl?:boolean}){
 const roles=owner?['admin','editor','viewer']:['editor','viewer'];
 const {tr}=useWorkspaceMessages();
 const descriptions:Record<string,string>={admin:tr('roleAdminDescription'),editor:tr('roleEditorDescription'),viewer:tr('roleViewerDescription')};
 return <Select className="fms-member-role" aria-label={tr('accessLevel')} variant="secondary" value={value} isDisabled={disabled} onChange={key=>{if(roles.includes(String(key)))onChange(String(key));}}>
  <Select.Trigger className="items-center"><Select.Value>{tr(('role'+value) as keyof WorkspaceMessages)}</Select.Value><Select.Indicator/></Select.Trigger>
  <Select.Popover className="fms-member-role-menu" UNSTABLE_portalContainer={portal}><ListBox>{roles.map(id=><ListBox.Item id={id} key={id} textValue={tr(('role'+id) as keyof WorkspaceMessages)}><div className="fms-member-role-copy"><Label>{tr(('role'+id) as keyof WorkspaceMessages)}</Label><Description>{descriptions[id]}</Description></div><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover>
 </Select>;
}
