import {Button, Checkbox, Label, Modal} from '@heroui/react';
import {Link01Icon, LockPasswordIcon, Folder01Icon, File01Icon, XIcon} from '@hugeicons/core-free-icons';
import {useState} from 'react';
import type {ManagementTransport, SharePage, ShareQuery, ShareRecord} from '../../management-contracts';
import {Icon} from '../../ui';
import {ShareDialog} from './ShareDialog';
import {ShareAnalyticsView} from './ShareAnalyticsView';
import {CursorFooter, ListState, ManagementSelect, ManagementTabs, PageFrame, VirtualRows, DateText, useManagementMessages, useCursorPage, type ManagementLocale} from './shared';
export function SharesPage({workspace,transport,portal,guestUploads=false,locale='en'}:{workspace:string;transport:ManagementTransport;portal:HTMLElement;guestUploads?:boolean;locale?:ManagementLocale}) {
  const t=useManagementMessages();
  const [status,setStatus]=useState<ShareQuery['status']>('active');
  const [kind,setKind]=useState<ShareQuery['kind']>('all');
  const [mine,setMine]=useState(false);
  const [analytics,setAnalytics]=useState<ShareRecord|null>(null);
  const [analyticsPortal,setAnalyticsPortal]=useState<HTMLDivElement|null>(null);
  const [selected,setSelected]=useState<ShareRecord|null>(null);
  const identity=JSON.stringify([workspace,status,kind,mine]);
  const page=useCursorPage<ShareRecord,SharePage>(transport.shares?(cursor,signal)=>transport.shares!(workspace,{status,kind,mine,summary:true},cursor,signal):undefined,identity);
  return <PageFrame title={t.shares} hint={t.sharesHint}>
    <div className="fms-management-tabs"><ManagementTabs label={t.shares} value={status} options={[["active",t.active],["expired",t.expired],["revoked",t.revoked]]} onChange={value=>setStatus(value as ShareQuery['status'])}/></div>
    <div className="fms-management-filters"><ManagementSelect label={t.allItems} value={kind} options={[["all",t.allItems],["file",t.files],["folder",t.folders],["selection",t.selections]]} onChange={value=>setKind(value as ShareQuery['kind'])} portal={portal}/><Checkbox className="fms-shares-mine" isSelected={mine} onChange={setMine}><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Checkbox.Content><Label>{t.mine}</Label></Checkbox.Content></Checkbox></div>
    <div className="fms-management-columns"><span>{t.name}</span><span>{t.access}</span><span>{t.created}</span><span/></div>
    {!page.data||!page.data.items.length?<ListState kind="shares" pending={page.pending} error={page.error} empty={t.shareEmpty} available={page.available} retry={page.refresh} t={t}/>:<VirtualRows items={page.data.items} identity={identity+page.cursor} getKey={item=>item.id} render={item=><>
      <div className="fms-management-identity"><Icon icon={item.kind==='folder'?Folder01Icon:item.kind==='selection'?Link01Icon:File01Icon}/><div><strong>{item.title}</strong><small>{item.entryCount} {t.items} · {t.expires}: {item.expiresAt?<DateText value={item.expiresAt} locale={locale}/>:t.never}</small></div></div>
      <div className="fms-management-access"><span>{item.allowDownload?t.downloads:t.viewOnly}</span>{item.passwordProtected&&<span title={t.passwordProtected}><Icon icon={LockPasswordIcon} size={16}/></span>}</div><time dateTime={item.createdAt}><DateText value={item.createdAt} locale={locale}/></time>
      <div className="fms-share-directory-actions">{transport.shareAnalytics&&item.collectAnalytics&&item.canEdit!==false&&<Button size="sm" variant="secondary" onPress={()=>setAnalytics(item)} aria-label={t.linkAnalytics}>{item.visits30d===undefined?t.linkAnalytics:new Intl.NumberFormat(locale).format(item.visits30d)+' '+t.visits.toLowerCase()}</Button>}{(item.canEdit??page.data?.canManage)&&transport.updateShare&&<Button size="sm" variant="tertiary" aria-label={`${t.manage}: ${item.title}`} onPress={()=>setSelected(item)}>{t.manage}</Button>}</div>
    </>}/>}
    <CursorFooter pageNumber={page.pageNumber} previous={page.data?.previousCursor??null} next={page.data?.nextCursor??null} busy={page.pending} onPage={page.setCursor} refresh={page.refresh} t={t}/>
    {analytics&&<Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open)setAnalytics(null);}} UNSTABLE_portalContainer={portal} className="fms-share-hub-backdrop"><Modal.Container size="lg" placement="center" className="fms-share-container"><Modal.Dialog aria-label={t.linkAnalytics} className="fms-share-dialog fms-share-hub is-analytics">
      <Modal.Header className="fms-share-head"><div className="fms-share-report-heading"><Modal.Heading>{t.linkAnalytics}</Modal.Heading><p title={analytics.title}>{analytics.title}</p></div><Button isIconOnly variant="ghost" aria-label={t.close} onPress={()=>setAnalytics(null)}><Icon icon={XIcon} size={24}/></Button></Modal.Header>
      <Modal.Body className="fms-share-body"><ShareAnalyticsView workspace={workspace} record={analytics} transport={transport} locale={locale} portal={analyticsPortal}/></Modal.Body>
      <div ref={setAnalyticsPortal} className="fms-management-portal"/>
    </Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>}
    {selected&&<ShareDialog workspace={workspace} record={selected} guestUploads={guestUploads} transport={transport} portal={portal} locale={locale} onClose={()=>setSelected(null)} onCreated={page.refresh} onUpdated={page.refresh}/>}
  </PageFrame>;
}
