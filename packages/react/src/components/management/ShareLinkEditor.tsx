import {useStudioTimeZone} from '../layout/TimePreferences';
import {Button, Calendar, DateField, DatePicker, Input, InputGroup, Label, Modal, Spinner, Switch, TextField} from '@heroui/react';
import {ArrowLeft01Icon, ArrowRight01Icon, Calendar03Icon, Copy01Icon, Link01Icon, LinkSquare02Icon, RefreshIcon, ChartHistogramIcon, Search01Icon, Download01Icon, FolderDownloadIcon, Upload01Icon, LockPasswordIcon, Clock01Icon, ViewIcon, ViewOffIcon, XIcon} from '@hugeicons/core-free-icons';
import {parseDate, parseAbsolute, toZoned, toCalendarDate, today, getLocalTimeZone} from '@internationalized/date';
import {useEffect, useId, useRef, useState} from 'react';
import type {Entry} from '../../contracts';
import type {ManagementTransport, ShareCreated, ShareRecord, ShareVisibility, SharePermission} from '../../management-contracts';
import {Icon, errorText} from '../../ui';
import {ConfirmDialog, useManagementMessages, type ManagementLocale} from './shared';

export type ShareDialogProps={workspace:string;entries?:readonly Pick<Entry,'id'|'name'|'kind'|'readOnly'>[];record?:ShareRecord;guestUploads?:boolean;transport:ManagementTransport;portal:HTMLElement;locale?:ManagementLocale;onClose:()=>void;onCreated?:(value:ShareCreated)=>void;onUpdated?:(value:ShareRecord)=>void;visibility?:ShareVisibility;linkPermission?:SharePermission;actor?:{name:string;owner:boolean}};
export function ShareLinkEditor({workspace,entries=[],record,guestUploads=false,transport,portal,locale='en',onClose,onCreated,onUpdated,visibility='share',linkPermission}:ShareDialogProps) {
  const t=useManagementMessages(), titleId=useId(),zone=useStudioTimeZone();
  const [selectPortal,setSelectPortal]=useState<HTMLDivElement|null>(null);
  const [current,setCurrent]=useState(record);
  const [title,setTitle]=useState(record?.title??(entries.length===1?entries[0]!.name.slice(0,160):''));
  const [collectAnalytics,setCollectAnalytics]=useState(record?.collectAnalytics??false);
  const [allowCopy,setAllowCopy]=useState(record?.allowCopy??false);
  const [allowIndexing,setAllowIndexing]=useState(record?.allowIndexing??false);
  const published=(record?.visibility??visibility)==='publish';
  const [allowDownload,setAllowDownload]=useState(record?.allowDownload??false);
  const [allowArchive,setAllowArchive]=useState(record?.allowArchive??false);
  const [allowUpload,setAllowUpload]=useState(record?.allowUpload??false);
  const [protectedLink,setProtectedLink]=useState(record?.passwordProtected??false);
  const [password,setPassword]=useState('');
  const [passwordVisible,setPasswordVisible]=useState(false);
  const [expires,setExpires]=useState(Boolean(record?.expiresAt));
  const [date,setDate]=useState(record?.expiresAt?toCalendarDate(parseAbsolute(record.expiresAt,zone)).toString():'');
  const [expiryChanged,setExpiryChanged]=useState(false);
  const [created,setCreated]=useState<ShareCreated|null>(null);
  const [copied,setCopied]=useState(false);
  const [linkUrl,setLinkUrl]=useState(''),[linkLoading,setLinkLoading]=useState(false);
  useEffect(()=>{if(!current?.hasRecoverableUrl||!transport.shareLink)return;const c=new AbortController();setLinkLoading(true);void transport.shareLink(workspace,current.id,c.signal).then(value=>{if(!c.signal.aborted)setLinkUrl(value.url);}).catch(e=>{if(!c.signal.aborted)setError(errorText(e));}).finally(()=>{if(!c.signal.aborted)setLinkLoading(false);});return()=>c.abort();},[workspace,current?.id,current?.hasRecoverableUrl,transport]);
  const [qr,setQr]=useState<string|null>(null);
  const [qrLoading,setQrLoading]=useState(false);
  const [error,setError]=useState('');
  const [busy,setBusy]=useState(false);
  const [confirmation,setConfirmation]=useState<'rotate'|'revoke'|'unpublish'|null>(null);
  const operation=useRef<AbortController|null>(null);
  useEffect(()=>()=>operation.current?.abort(),[workspace]);
  const validPassword=!protectedLink||(current?.passwordProtected&&!password)||(password.length>=8&&password.length<=128);
  const validDate=Boolean(!expires||(current?.expiresAt&&!expiryChanged)||(date&&date>=today(zone).toString()));
  const valid=title.trim().length>0&&title.trim().length<=160&&validPassword&&validDate&&(Boolean(current)||entries.length>0&&entries.length<=120);
  const mutate=async(action:'save'|'rotate'|'revoke'|'unpublish')=>{
    if(operation.current||busy||action==='save'&&!valid)return;
    const controller=new AbortController();operation.current=controller;setBusy(true);setError('');
    try {
      let result:ShareRecord|ShareCreated;
      if(action==='rotate') {
        if(!current||!transport.rotateShare)return;
        result=await transport.rotateShare(workspace,current.id,current.revision,controller.signal);
      } else if(action==='revoke'||action==='unpublish') {
        if(!current||!transport.updateShare)return;
        result=await transport.updateShare(workspace,current.id,{revision:current.revision,revoked:true,...(action==='unpublish'?{unpublish:true}:{})},controller.signal);
      } else {
        const deadline=expires&&date?toZoned(parseDate(date),zone).set({hour:23,minute:59,second:59,millisecond:999}).toDate():null;
        const expiresAt=deadline?.toISOString()??null;
        const input={title:title.trim(),...(transport.shareAnalytics?{collectAnalytics}:{}),...(published?{allowIndexing:allowIndexing&&!protectedLink,allowCopy:allowDownload&&allowCopy}:{}),allowDownload,allowArchive:allowDownload&&allowArchive,...(guestUploads?{allowUpload}:{}),
          ...(!protectedLink?{password:null}:password?{password}:{})};
        if(current&&transport.updateShare) {
          result=await transport.updateShare(workspace,current.id,{...input,revision:current.revision,...(expiryChanged?{expiresAt}:{})},controller.signal);
        } else if(!current&&transport.createShare) {
          result=await transport.createShare(workspace,{...input,...(published?{visibility}:linkPermission?{recipientPermission:linkPermission}:{}),expiresAt,entryIds:entries.map(entry=>entry.id)},controller.signal);
        }
        else return;
      }
      if(controller.signal.aborted)return;
      setCurrent(result);setConfirmation(null);
      if('url' in result){setCreated(result);setCopied(false);setQr(null);onCreated?.(result);}
      else {setExpiryChanged(false);setPassword('');onUpdated?.(result);if(action==='revoke'||action==='unpublish')onClose();}
    } catch(reason){if(!controller.signal.aborted)setError(errorText(reason));}
    finally {if(operation.current===controller)operation.current=null;if(!controller.signal.aborted)setBusy(false);}
  };
  const copy=async()=>{const url=created?.url??linkUrl;if(!url)return;try{await navigator.clipboard.writeText(url);setCopied(true);}catch{setError(t.copyFailed);}};
  const showQr=async()=>{if(!created||qrLoading)return;if(qr){setQr(null);return;}setQrLoading(true);setError('');try{
    const moduleUrl=new URL('./share-qr.js',import.meta.url).href;
    const module: {encodeShareQr:(url:string)=>Promise<string>}=await import(moduleUrl);
    setQr(await module.encodeShareQr(created.url));
  }catch{setError(t.qrFailed);}finally{setQrLoading(false);}};
  const canSave=current?Boolean(transport.updateShare)&&current.canEdit!==false:Boolean(transport.createShare);
  return <>
    <Modal><Modal.Backdrop isOpen isDismissable={!busy} isKeyboardDismissDisabled={busy} onOpenChange={open=>{if(!open&&!busy)onClose();}} UNSTABLE_portalContainer={portal} className="fms-share-editor-backdrop">
      <Modal.Container size="md" placement="center"><Modal.Dialog aria-labelledby={titleId} className="fms-management-dialog fms-share-dialog fms-share-editor">
        <Modal.Header className="fms-share-head"><div className="fms-share-editor-heading"><span className="fms-share-editor-icon"><Icon icon={Link01Icon} size={24}/></span><div><Modal.Heading id={titleId}>{created?t.link:current?t.manage:published?t.createPublish:t.createLink}</Modal.Heading>{current&&<p>{current.title}</p>}</div></div>{current&&transport.rotateShare&&canSave&&<Button isIconOnly variant="ghost" aria-label={t.rotate} isDisabled={busy||!!current.revokedAt} onPress={()=>{setError('');setConfirmation('rotate');}}><Icon icon={RefreshIcon} size={18}/></Button>}<Button isIconOnly variant="ghost" aria-label={t.close} isDisabled={busy} onPress={onClose}><Icon icon={XIcon} size={24}/></Button></Modal.Header>
        <Modal.Body><div className="fms-share-form">
          {current&&!created&&<TextField isRequired isDisabled={!canSave||busy}><Label>{t.title}</Label><Input variant="secondary" value={title} maxLength={160} onChange={event=>setTitle(event.target.value)}/></TextField>}
          {current&&!created&&<div className="fms-share-current-link"><TextField><Label>URL</Label><Input variant="secondary" readOnly value={linkUrl} placeholder={linkLoading?t.loading:'—'} aria-label={t.link}/></TextField><Button variant="secondary" isDisabled={!linkUrl||linkLoading} onPress={()=>void copy()}>{copied?t.copied:t.copyLink}</Button></div>}
          {created?<><p>{current?.hasRecoverableUrl?t.recoverableHint:t.createdHint}</p><TextField><Label>{t.link}</Label><Input variant="secondary" value={created.url} readOnly autoFocus onFocus={event=>event.currentTarget.select()}/></TextField><Button variant="secondary" onPress={()=>void copy()}><Icon icon={Copy01Icon} size={18}/>{copied?t.copied:t.copyLink}</Button><Button variant="tertiary" isDisabled={qrLoading} onPress={()=>void showQr()}>{qrLoading?<Spinner size="sm"/>:null}{qr?t.hideQr:t.showQr}</Button>{qr&&<img src={qr} alt={t.qrAlt} width={200} height={200}/>}</>:<>
            {!current&&<TextField isRequired isDisabled={!canSave||busy}><Label>{t.title}</Label><Input variant="secondary" value={title} maxLength={160} onChange={event=>setTitle(event.target.value)}/></TextField>}
            {transport.shareAnalytics&&<Switch isSelected={collectAnalytics} onChange={setCollectAnalytics} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={ChartHistogramIcon} size={20} strokeWidth={1.7}/><Label>{t.collectEngagement}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>}
            {published&&<Switch isSelected={allowIndexing&&!protectedLink} onChange={setAllowIndexing} isDisabled={!canSave||busy||protectedLink}><Switch.Content className="fms-share-option"><Icon icon={Search01Icon} size={20} strokeWidth={1.7}/><Label>{t.allowIndexing}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>}
            <Switch isSelected={allowDownload} onChange={setAllowDownload} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={Download01Icon} size={20} strokeWidth={1.7}/><Label>{t.downloadPermission}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>
            {published&&<Switch isSelected={allowDownload&&allowCopy} onChange={setAllowCopy} isDisabled={!canSave||busy||!allowDownload}><Switch.Content className="fms-share-option"><Icon icon={Copy01Icon} size={20} strokeWidth={1.7}/><Label>{t.allowCopy}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>}
            {allowDownload&&<Switch isSelected={allowArchive} onChange={setAllowArchive} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={FolderDownloadIcon} size={20} strokeWidth={1.7}/><Label>{t.archivePermission}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>}
            {guestUploads&&(current?.kind==='folder'||(!current&&entries.length===1&&entries[0]?.kind==='folder'&&!entries[0]?.readOnly))&&<Switch isSelected={allowUpload} onChange={setAllowUpload} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={Upload01Icon} size={20} strokeWidth={1.7}/><Label>{t.uploadPermission}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>}
            <Switch isSelected={protectedLink} onChange={value=>{setProtectedLink(value);if(!value)setPasswordVisible(false);}} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={LockPasswordIcon} size={20} strokeWidth={1.7}/><Label>{t.passwordPermission}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>
            {protectedLink&&<TextField isDisabled={!canSave||busy} isInvalid={!validPassword}><Label>{t.password}</Label><InputGroup variant="secondary" fullWidth className="fms-share-password"><InputGroup.Input type={passwordVisible?'text':'password'} autoComplete="new-password" value={password} minLength={8} maxLength={128} {...(current?.passwordProtected?{placeholder:'••••••••'}:{})} onChange={event=>setPassword(event.target.value)}/><InputGroup.Suffix><Button type="button" isIconOnly size="sm" variant="ghost" isDisabled={!canSave||busy} aria-label={passwordVisible?(locale==='pl'?'Ukryj hasło':'Hide password'):(locale==='pl'?'Pokaż hasło':'Show password')} aria-pressed={passwordVisible} onPress={()=>setPasswordVisible(value=>!value)}><Icon icon={passwordVisible?ViewOffIcon:ViewIcon} size={18}/></Button></InputGroup.Suffix></InputGroup><p className="fms-management-muted">{t.passwordHint}</p></TextField>}
            <Switch isSelected={expires} onChange={value=>{setExpires(value);setExpiryChanged(true);}} isDisabled={!canSave||busy}><Switch.Content className="fms-share-option"><Icon icon={Clock01Icon} size={20} strokeWidth={1.7}/><Label>{t.expiryPermission}</Label><Switch.Control><Switch.Thumb/></Switch.Control></Switch.Content></Switch>
            {expires&&<DatePicker value={date?parseDate(date):null} onChange={value=>{setDate(value?.toString()??'');setExpiryChanged(true);}} minValue={today(zone)} granularity="day" isDisabled={!canSave||busy} isInvalid={!validDate}>
              <Label>{t.expirationDate}</Label><DateField.Group variant="secondary"><DateField.Input>{segment=><DateField.Segment segment={segment}/>}</DateField.Input><DateField.Suffix><DatePicker.Trigger aria-label={t.expirationDate}><DatePicker.TriggerIndicator><Icon icon={Calendar03Icon} size={18}/></DatePicker.TriggerIndicator></DatePicker.Trigger></DateField.Suffix></DateField.Group>
              <DatePicker.Popover {...(selectPortal?{UNSTABLE_portalContainer:selectPortal}:{})}><Calendar aria-label={t.expirationDate}><Calendar.Header><Calendar.Heading/><Calendar.NavButton slot="previous"><Icon icon={ArrowLeft01Icon}/></Calendar.NavButton><Calendar.NavButton slot="next"><Icon icon={ArrowRight01Icon}/></Calendar.NavButton></Calendar.Header><Calendar.Grid><Calendar.GridHeader>{day=><Calendar.HeaderCell>{day}</Calendar.HeaderCell>}</Calendar.GridHeader><Calendar.GridBody>{value=><Calendar.Cell date={value}/>}</Calendar.GridBody></Calendar.Grid></Calendar></DatePicker.Popover>
            </DatePicker>}

          </>}
          {error&&!confirmation&&<p role="alert" className="fms-error">{error}</p>}
        </div></Modal.Body>
        <Modal.Footer className="fms-share-footer">{current&&!created&&<>{canSave&&published&&<Button variant="secondary" isDisabled={busy||!!current.revokedAt} onPress={()=>{setError('');setConfirmation('unpublish');}}>{locale==='pl'?'Cofnij publikację':'Unpublish'}</Button>}{canSave&&<Button variant="secondary" isDisabled={busy||!!current.revokedAt} onPress={()=>{setError('');setConfirmation('revoke');}}>{locale==='pl'?'Wyłącz link':'Disable link'}</Button>}<Button variant="secondary" isDisabled={!linkUrl||linkLoading} onPress={()=>window.open(linkUrl,'_blank','noopener,noreferrer')}>{locale==='pl'?'Odwiedź URL':'Visit URL'}</Button></>}{created?<Button onPress={onClose}>{t.done}</Button>:<Button onPress={()=>void mutate('save')} isDisabled={!canSave||!valid||busy}>{busy&&<Spinner size="sm"/>}{current?t.save:published?t.createPublish:t.createLink}</Button>}</Modal.Footer>
        <div ref={setSelectPortal} className="fms-management-portal"/>
      </Modal.Dialog></Modal.Container>
    </Modal.Backdrop></Modal>
    {confirmation&&<ConfirmDialog portal={selectPortal??portal} title={confirmation==='unpublish'?(locale==='pl'?'Cofnąć publikację?':'Unpublish this link?'):confirmation==='revoke'?t.revokeTitle:t.rotateTitle} hint={confirmation==='unpublish'?(locale==='pl'?'Publiczny adres przestanie działać. Pliki pozostaną w bibliotece.':'The public URL will stop working. Library files are retained.'):confirmation==='revoke'?t.revokeHint:t.rotateHint} confirm={confirmation==='unpublish'?(locale==='pl'?'Cofnij publikację':'Unpublish'):confirmation==='revoke'?t.revoke:t.continue} danger={confirmation!=='rotate'} busy={busy} error={error} onConfirm={()=>void mutate(confirmation)} onClose={()=>{setConfirmation(null);setError('');}} t={t}/>}
  </>;
}
