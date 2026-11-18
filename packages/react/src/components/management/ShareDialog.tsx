import {Button,Dropdown,Input,Label,ListBox,Modal,Select,Spinner,Tabs,TextField} from '@heroui/react';
import {ArrowLeft01Icon,File01Icon,Folder01Icon,Globe02Icon,MoreVerticalIcon,XIcon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {SharePage,SharePermission,ShareRecord,ShareVisibility} from '../../management-contracts';
import {Icon,errorText} from '../../ui';
import {ShareLinkEditor,type ShareDialogProps} from './ShareLinkEditor';
import {ShareAnalyticsView} from './ShareAnalyticsView';
import {ConfirmDialog,CursorFooter,DateText,useManagementMessages,useCursorPage} from './shared';

export function ShareDialog(props:ShareDialogProps) {
  return props.record?<ShareLinkEditor {...props}/>:<ShareHub {...props}/>;
}

function ShareSelect<T extends string>({label,value,options,onChange,portal,disabled=false}:{
  label:string;value:T;options:readonly {id:T;label:string}[];onChange:(value:T)=>void;portal:HTMLElement|null;disabled?:boolean;
}) {
  return <Select className="fms-share-select" variant="secondary" aria-label={label} value={value} isDisabled={disabled} onChange={key=>{
    const option=options.find(item=>item.id===key);
    if(option)onChange(option.id);
  }}>
    <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
    <Select.Popover {...(portal?{UNSTABLE_portalContainer:portal}:{})}>
      <ListBox>{options.map(option=><ListBox.Item key={option.id} id={option.id} textValue={option.label}>
        <Label>{option.label}</Label><ListBox.ItemIndicator/>
      </ListBox.Item>)}</ListBox>
    </Select.Popover>
  </Select>;
}

function ShareHub({workspace,entries=[],guestUploads=false,transport,portal,locale='en',onClose,actor}:ShareDialogProps) {
  const t=useManagementMessages();
  const [tab,setTab]=useState<ShareVisibility>('share');
  const [overlay,setOverlay]=useState<HTMLDivElement|null>(null);
  const [editor,setEditor]=useState<{record?:ShareRecord;permission?:SharePermission}|null>(null);
  const [analytics,setAnalytics]=useState<ShareRecord|null>(null);
  const [confirm,setConfirm]=useState<{record:ShareRecord;action:'delete'|'disable'}|null>(null);
  const [emails,setEmails]=useState('');
  const [permission,setPermission]=useState<SharePermission>('view');
  const [error,setError]=useState('');
  const [notice,setNotice]=useState('');
  const [busy,setBusy]=useState(false);
  const mutation=useRef<AbortController|null>(null);
  const name=entries.length===1?entries[0]!.name:`${entries.length} ${t.items}`;
  const root=entries[0]?.id;
  const page=useCursorPage<ShareRecord,SharePage>(root&&transport.shares?(cursor,signal)=>transport.shares!(workspace,{status:'all',kind:'all',mine:false,visibility:tab,entry:root,summary:true},cursor,signal):undefined,`${workspace}:${root}:${tab}`);
  const records=(page.data?.items??[]).filter(item=>(item.visibility??'share')===tab);
  const links=records.filter(item=>!item.recipientEmail);
  const people=records.filter(item=>item.recipientEmail);
  const active=links.find(item=>!item.revokedAt&&(!item.expiresAt||Date.parse(item.expiresAt)>Date.now()));
  const recipients=emails.split(',').map(email=>email.trim()).filter(Boolean);
  const permissions=[{id:'view',label:t.canView},{id:'comment',label:t.canComment},{id:'edit',label:t.canEdit}] as const;
  const linkOptions=[{id:'disabled',label:t.linkDisabled},...permissions] as const;
  const run=async(action:(signal:AbortSignal)=>Promise<void>)=>{
    if(mutation.current)return;
    const controller=new AbortController();
    mutation.current=controller;setBusy(true);setError('');setNotice('');
    try{await action(controller.signal);}catch(reason){if(!controller.signal.aborted)setError(errorText(reason));}
    finally{if(mutation.current===controller)mutation.current=null;if(!controller.signal.aborted)setBusy(false);}
  };
  useEffect(()=>()=>mutation.current?.abort(),[]);
  const copy=(record:ShareRecord)=>void run(async signal=>{
    if(!transport.shareLink)return;
    const {url}=await transport.shareLink(workspace,record.id,signal);
    if(!signal.aborted){await navigator.clipboard.writeText(url);setNotice(t.copied);}
  });
  const open=(record:ShareRecord)=>{
    if(!transport.shareLink||mutation.current)return;
    const tab=window.open('about:blank','_blank');
    if(tab)tab.opener=null;
    void run(async signal=>{
      try{
        const {url}=await transport.shareLink!(workspace,record.id,signal);
        if(signal.aborted){tab?.close();return;}
        if(tab)tab.location.replace(url);
        else setOpenUrl(url);
      }catch(error){tab?.close();throw error;}
    });
  };
  const [openUrl,setOpenUrl]=useState('');
  const toggle=(record:ShareRecord,revoked:boolean)=>void run(async signal=>{
    await transport.updateShare?.(workspace,record.id,{revision:record.revision,revoked},signal);
    if(!signal.aborted){setConfirm(null);page.refresh();}
  });
  const menu=(record:ShareRecord)=><Dropdown><Button isIconOnly size="sm" variant="ghost" aria-label={t.manage} isDisabled={busy}><Icon icon={MoreVerticalIcon} size={18}/></Button>
    <Dropdown.Popover {...(overlay?{UNSTABLE_portalContainer:overlay}:{})}><Dropdown.Menu aria-label={t.manage} onAction={action=>{
      if(action==='open')open(record);
      if(action==='copy')copy(record);
      if(action==='edit')setEditor({record});
      if(action==='analytics')setAnalytics(record);
      if(action==='disable')setConfirm({record,action:'disable'});
      if(action==='enable')toggle(record,false);
      if(action==='delete')setConfirm({record,action:'delete'});
    }}>
      {record.hasRecoverableUrl&&!record.revokedAt&&transport.shareLink&&<Dropdown.Item id="open">{t.openLink}</Dropdown.Item>}
      {record.hasRecoverableUrl&&!record.revokedAt&&transport.shareLink&&<Dropdown.Item id="copy">{t.copyLink}</Dropdown.Item>}
      {!record.recipientEmail&&transport.updateShare&&<Dropdown.Item id="edit">{t.editLink}</Dropdown.Item>}
      {!record.recipientEmail&&transport.shareAnalytics&&(record.collectAnalytics||tab==='publish')&&<Dropdown.Item id="analytics">{t.linkAnalytics}</Dropdown.Item>}
      {transport.updateShare&&<Dropdown.Item id={record.revokedAt?'enable':'disable'}>{record.revokedAt?t.enableLink:t.disableLink}</Dropdown.Item>}
      {transport.deleteShare&&<Dropdown.Item id="delete" variant="danger">{t.deleteLink}</Dropdown.Item>}
    </Dropdown.Menu></Dropdown.Popover></Dropdown>;
  const linkList=<>
    {links.length>0&&<div className="fms-share-list">{links.map(record=><div key={record.id} className="fms-share-list-row">
      <div className="fms-share-link-name"><strong>{record.title}</strong><small><DateText value={record.createdAt} locale={locale}/>{record.lastVisitedAt&&<> · {t.lastVisited} <DateText value={record.lastVisitedAt} locale={locale}/></>} · {record.revokedAt?t.revoked:record.expiresAt&&Date.parse(record.expiresAt)<=Date.now()?t.expired:t.active}</small></div>
      {transport.shareAnalytics&&record.canEdit!==false&&record.collectAnalytics&&<Button size="sm" variant="secondary" onPress={()=>setAnalytics(record)} aria-label={t.linkAnalytics}>{record.visits30d===undefined?t.linkAnalytics:new Intl.NumberFormat(locale).format(record.visits30d)+' '+t.visits.toLowerCase()}</Button>}
      {transport.shareLink&&record.hasRecoverableUrl&&!record.revokedAt&&<Button size="sm" variant="tertiary" onPress={()=>copy(record)}>{t.copyLink}</Button>}
      {record.canEdit!==false&&menu(record)}
    </div>)}</div>}
    {(page.data?.previousCursor||page.data?.nextCursor)&&<CursorFooter pageNumber={page.pageNumber} previous={page.data.previousCursor??null} next={page.data.nextCursor} busy={page.pending} onPage={page.setCursor} refresh={page.refresh} t={t}/>}
    {openUrl&&<a className="fms-share-open" href={openUrl} target="_blank" rel="noopener noreferrer">{t.openLink} ↗</a>}
    {notice&&<p role="status" className="fms-management-muted">{notice}</p>}
    {error&&!confirm&&<p role="alert" className="fms-error">{error}</p>}
  </>;
  return <Modal><Modal.Backdrop isOpen isDismissable={!busy&&!editor&&!analytics&&!confirm} isKeyboardDismissDisabled={Boolean(editor||analytics||confirm)||busy} onOpenChange={value=>{if(!value&&!busy)onClose();}} UNSTABLE_portalContainer={portal} className="fms-share-hub-backdrop">
    <Modal.Container size="md" placement="center" className="fms-share-container"><Modal.Dialog aria-label={t.share+' '+name} className={`fms-share-dialog fms-share-hub${analytics?' is-analytics':''}`}>
      <Modal.Header className="fms-share-head" inert={Boolean(editor||confirm)}>
        {analytics&&<Button isIconOnly variant="ghost" aria-label={t.backToLinks} onPress={()=>setAnalytics(null)}><Icon icon={ArrowLeft01Icon}/></Button>}
        {analytics?<div className="fms-share-report-heading"><Modal.Heading>{t.linkAnalytics}</Modal.Heading><p title={analytics.title}>{analytics.title}</p></div>:<Modal.Heading title={name}>{`${t.share} ‘${name}’`}</Modal.Heading>}
        <Button isIconOnly variant="ghost" aria-label={t.close} onPress={onClose}><Icon icon={XIcon} size={24}/></Button>
      </Modal.Header>
      <Modal.Body className="fms-share-body" inert={Boolean(editor||confirm)}>
        {analytics?<ShareAnalyticsView workspace={workspace} record={analytics} transport={transport} locale={locale} portal={overlay}/>:<Tabs
          selectedKey={tab} onSelectionChange={key=>{
            if(key!=='share'&&key!=='publish')return;
            setTab(key);setError('');setNotice('');setOpenUrl('');
          }}>
          <Tabs.ListContainer className="fms-share-tabs-list"><Tabs.List aria-label={t.share}>
            <Tabs.Tab id="share">{t.share}<Tabs.Indicator/></Tabs.Tab>
            <Tabs.Tab id="publish">{t.publish}<Tabs.Indicator/></Tabs.Tab>
          </Tabs.List></Tabs.ListContainer>
          <Tabs.Panel id="share" className="fms-share-panel">
            {transport.inviteShare&&<form className="fms-share-invite" onSubmit={event=>{
              event.preventDefault();
              if(!recipients.length||recipients.length>10)return;
              void run(async signal=>{
                const result=await transport.inviteShare!(workspace,{title:name.slice(0,160),entryIds:entries.map(entry=>entry.id),emails:recipients,permission},signal);
                if(!signal.aborted){setEmails('');page.refresh();setNotice(result.failed.length?t.invitePartial+' '+result.failed.join(', '):t.inviteSent);}
              });
            }}>
              <TextField className="fms-share-email" aria-label={t.inviteEmails} value={emails} onChange={setEmails}><Input variant="secondary" placeholder={t.inviteEmails}/></TextField>
              <ShareSelect label={t.access} value={permission} options={permissions} onChange={setPermission} portal={overlay} disabled={busy}/>
              <Button type="submit" isDisabled={busy||!recipients.length||recipients.length>10}>{t.invite}</Button>
            </form>}
            <div className="fms-share-anyone"><span className="fms-share-globe"><Icon icon={Globe02Icon} size={18}/></span><span>{t.anyoneWithLink}</span>
              <ShareSelect label={t.linkAccess} value={active?.recipientPermission??(active?'view':'disabled')} options={linkOptions} portal={overlay}
                disabled={busy||page.pending||(active?active.canEdit===false||!transport.updateShare:!transport.createShare)} onChange={value=>{
                  if(value==='disabled'){if(active)setConfirm({record:active,action:'disable'});return;}
                  if(!active){setEditor({permission:value});return;}
                  void run(async signal=>{await transport.updateShare?.(workspace,active.id,{revision:active.revision,recipientPermission:value},signal);if(!signal.aborted)page.refresh();});
                }}/>
              <Button variant="secondary" isDisabled={!active?.hasRecoverableUrl||!transport.shareLink||busy} onPress={()=>active&&copy(active)}>{t.copyLink}</Button>
            </div>
          {page.pending&&<Spinner size="sm" aria-label={t.loading}/>}
          {page.error&&<p role="alert">{page.error} <Button onPress={page.refresh}>{t.retry}</Button></p>}
          {actor?.name&&<div className="fms-share-owner"><span className="fms-share-avatar">{actor.name[0]?.toUpperCase()}</span><strong>{actor.name} <small>({t.me})</small></strong>{actor.owner&&<span>{t.shareOwner}</span>}</div>}
          {people.length>0&&<div className="fms-share-list">{people.map(record=><div key={record.id} className="fms-share-list-row"><span className="fms-share-avatar">{record.recipientEmail?.[0]?.toUpperCase()}</span><strong>{record.recipientEmail}</strong>
            <ShareSelect label={t.access+' '+record.recipientEmail} value={record.recipientPermission??'view'} options={permissions} portal={overlay}
              disabled={busy||record.canEdit===false||!transport.updateShare||Boolean(record.revokedAt)} onChange={recipientPermission=>{
                void run(async signal=>{await transport.updateShare?.(workspace,record.id,{revision:record.revision,recipientPermission},signal);if(!signal.aborted)page.refresh();});
              }}/>
            {record.revokedAt&&<small>{t.revoked}</small>}{record.canEdit!==false&&menu(record)}</div>)}</div>}
          {linkList}
          </Tabs.Panel>
          <Tabs.Panel id="publish" className="fms-share-panel">
            {page.pending?<Spinner size="sm" aria-label={t.loading}/>:page.error?<p role="alert">{page.error} <Button onPress={page.refresh}>{t.retry}</Button></p>:links.length?<div className="fms-publish-toolbar">
              <h3>{t.publishedLinks}</h3><Button variant="primary" isDisabled={!transport.createShare} onPress={()=>setEditor({})}>{t.newPublishLink}</Button>
            </div>:<div className="fms-publish-empty">
              <h3>{t.publishHint}</h3>
              <Button variant="secondary" className="fms-publish-create" isDisabled={!transport.createShare} onPress={()=>setEditor({})}>{t.newPublishLink}</Button>
              <div className="fms-publish-window" aria-hidden="true">
                <div className="fms-publish-window-bar"><i/><i/><i/></div>
                <div className="fms-publish-file">
                  <Icon icon={entries[0]?.kind==='folder'?Folder01Icon:File01Icon} size={23}/>
                  <strong>{name}</strong>
                </div>
              </div>
            </div>}
            {linkList}
          </Tabs.Panel>
        </Tabs>}
      </Modal.Body>
      <div ref={setOverlay}/>
      {editor&&overlay&&<ShareLinkEditor key={editor.record?.id??tab} workspace={workspace} entries={entries} {...(editor.record?{record:editor.record}:{})} guestUploads={guestUploads} transport={transport} portal={overlay} locale={locale} visibility={tab} {...(editor.permission?{linkPermission:editor.permission}:{})} onClose={()=>setEditor(null)} onCreated={()=>page.refresh()} onUpdated={()=>page.refresh()}/>}
      {confirm&&overlay&&<ConfirmDialog portal={overlay} title={confirm.action==='delete'?t.deleteLink:t.disableLink} hint={confirm.action==='delete'?t.deleteLinkHint:t.revokeHint} confirm={confirm.action==='delete'?t.deleteLink:t.disableLink} danger busy={busy} error={error} onClose={()=>{setConfirm(null);setError('');}} onConfirm={()=>{
        if(confirm.action==='disable')toggle(confirm.record,true);
        else void run(async signal=>{await transport.deleteShare?.(workspace,confirm.record.id,confirm.record.revision,signal);if(!signal.aborted){setConfirm(null);page.refresh();}});
      }} t={t}/>}
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
