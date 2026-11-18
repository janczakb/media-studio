import {EmptyState,Spinner} from '@heroui/react';
import {ChartLineData01Icon,Delete02Icon,Folder01Icon,Key01Icon,Link01Icon,Mail01Icon,Notification03Icon,Tag01Icon,UserGroupIcon} from '@hugeicons/core-free-icons';
import {Icon} from '../ui';
const icons={members:UserGroupIcon,invitations:Mail01Icon,tokens:Key01Icon,usage:ChartLineData01Icon,shares:Link01Icon,trash:Delete02Icon,tags:Tag01Icon,notifications:Notification03Icon,files:Folder01Icon};
export type TableEmptyKind=keyof typeof icons;
export function TableEmptyState({kind,label,pending=false}:{kind:TableEmptyKind;label:string;pending?:boolean}){
 return <EmptyState className="fms-table-empty" data-empty-kind={kind} role="status">{pending?<Spinner size="sm"/>:<Icon icon={icons[kind]} size={28}/>}<span>{label}</span></EmptyState>;
}
