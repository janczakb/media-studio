import {TableEmptyState} from '../TableEmptyState';
import {useWorkspaceMessages} from '../../workspace-i18n';
import {Avatar,Button,Chip,Pagination,Spinner,Table} from '@heroui/react';
import {createColumnHelper,createPaginatedRowModel,flexRender,rowPaginationFeature,tableFeatures,useTable,type PaginationState} from '@tanstack/react-table';
import {useEffect,useMemo,useRef,useState} from 'react';
import type {MemberPage,StudioTransport,Workspace,WorkspaceMember} from '../../contracts';
import {MemberRoleSelect,memberRoleName} from '../management/MemberRoleSelect';

const features=tableFeatures({paginatedRowModel:createPaginatedRowModel(),rowPaginationFeature});
const helper=createColumnHelper<typeof features,WorkspaceMember>();
export function MembersTable({workspace,transport,portal,pl,revision,busy,onRole,onRemove}:{workspace:Workspace;transport:StudioTransport;portal:HTMLElement;pl:boolean;revision:number;busy:boolean;onRole:(person:WorkspaceMember,role:string)=>void;onRemove:(person:WorkspaceMember)=>void}){
 return <MemberTablePage key={workspace.id+':'+revision} {...{workspace,transport,portal,pl,revision,busy,onRole,onRemove}}/>;
}
function MemberTablePage({workspace,transport,portal,pl,busy,onRole,onRemove}:Parameters<typeof MembersTable>[0]){
 const {messages,tr}=useWorkspaceMessages();
 const [pagination,setPagination]=useState<PaginationState>({pageIndex:0,pageSize:25});
 const [page,setPage]=useState<MemberPage|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true),[retry,setRetry]=useState(0);
 const cursors=useRef<(string|null)[]>([null]);
 useEffect(()=>{const controller=new AbortController();setLoading(true);setError('');void transport.members!(workspace.id,cursors.current[pagination.pageIndex]??null,controller.signal).then(result=>{if(!controller.signal.aborted){setPage(result);if(result.nextCursor)cursors.current[pagination.pageIndex+1]=result.nextCursor;}}).catch(reason=>{if(!controller.signal.aborted)setError(reason instanceof Error?reason.message:'Error');}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});return()=>controller.abort();},[workspace.id,transport,pagination.pageIndex,retry]);
 const columns=useMemo(()=>helper.columns([
  helper.accessor('name',{header:tr('member'),cell:info=><div className="fms-member-table-identity"><Avatar size="sm" className="fms-member-avatar">{info.row.original.avatarUrl&&<Avatar.Image src={info.row.original.avatarUrl} alt=""/>}<Avatar.Fallback>{info.getValue()?.slice(0,1)??'?'}</Avatar.Fallback></Avatar><span><strong>{info.getValue()??(tr('member'))}</strong><small>{info.row.original.email}</small></span></div>}),
  helper.accessor('role',{header:tr('accessLevel'),cell:info=>info.row.original.canRemove&&workspace.permissions?.['members.manage']===true&&transport.updateMember?<MemberRoleSelect value={info.getValue()} onChange={role=>onRole(info.row.original,role)} owner={workspace.role==='owner'} disabled={busy||loading} portal={portal} pl={pl}/>:memberRoleName(info.getValue(),messages)}),
  helper.display({id:'status',header:tr('memberStatus'),cell:()=><Chip size="sm" variant="soft" color="success">{tr('memberActive')}</Chip>}),
  helper.display({id:'actions',header:tr('memberActions'),cell:info=>info.row.original.canRemove&&workspace.permissions?.['members.manage']===true&&transport.removeMember&&<Button size="sm" variant="danger-soft" isDisabled={busy||loading} onPress={()=>onRemove(info.row.original)}>{tr('removeAccess')}</Button>}),
 ]),[pl,workspace.role,busy,portal,onRole,onRemove,messages,tr,loading,transport,workspace.permissions]);
 const table=useTable({columns,data:page?.items??[],features,manualPagination:true,pageCount:page?.hasMore?-1:pagination.pageIndex+1,onPaginationChange:setPagination,state:{pagination}});
 const start=pagination.pageIndex*25;
 return <div className="fms-members-table"><Table><Table.ScrollContainer><Table.Content aria-label={tr('peopleWithAccess')}><Table.Header>{table.getHeaderGroups()[0]?.headers.map(header=><Table.Column key={header.id} id={header.id} isRowHeader={header.id==='name'}>{flexRender(header.column.columnDef.header,header.getContext())}</Table.Column>)}</Table.Header><Table.Body renderEmptyState={()=><TableEmptyState kind="members" pending={loading} label={loading?tr('membersLoading'):error||tr('noWorkspaceMembers')}/>}>{table.getRowModel().rows.map(row=><Table.Row key={row.original.id} id={row.original.id}>{row.getAllCells().map(cell=><Table.Cell key={cell.id}>{flexRender(cell.column.columnDef.cell,cell.getContext())}</Table.Cell>)}</Table.Row>)}</Table.Body></Table.Content></Table.ScrollContainer>{(loading||!!page?.items.length)&&<Table.Footer><Pagination size="sm"><Pagination.Summary>{page?.items.length?`${start+1}–${start+page.items.length}`:loading?(tr('membersLoading')):(tr('noWorkspaceMembers'))}</Pagination.Summary><Pagination.Content><Pagination.Item><Pagination.Previous isDisabled={loading||busy||pagination.pageIndex===0} onPress={()=>table.previousPage()}><Pagination.PreviousIcon/>{tr('membersPrevious')}</Pagination.Previous></Pagination.Item><Pagination.Item><Pagination.Link isActive>{pagination.pageIndex+1}</Pagination.Link></Pagination.Item><Pagination.Item><Pagination.Next isDisabled={loading||busy||!page?.hasMore} onPress={()=>table.nextPage()}>{tr('membersNext')}<Pagination.NextIcon/></Pagination.Next></Pagination.Item></Pagination.Content></Pagination></Table.Footer>}</Table>{loading&&<Spinner size="sm"/>}{error&&<p role="alert">{error}<Button size="sm" onPress={()=>setRetry(value=>value+1)}>{tr('tryAgain')}</Button></p>}</div>;
}
