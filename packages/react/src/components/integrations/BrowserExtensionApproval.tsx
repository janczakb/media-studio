import {Alert, Button, Label, ListBox, Modal, Select} from '@heroui/react';
import {useEffect, useRef, useState} from 'react';
import type {StudioTransport, Workspace} from '../../contracts';
import {useStudioNamespace} from '../../workspace-i18n';

export function BrowserExtensionApproval({transport,workspaces,workspace,portal}:{transport:StudioTransport;workspaces:Workspace[];workspace:string|undefined;locale:string;portal:HTMLDivElement}) {
  const t=useStudioNamespace('integrations');
  const [request,setRequest]=useState<{id:string;code:string}|null>(null);
  const [target,setTarget]=useState(workspace??'');
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState('');
  const [done,setDone]=useState(false);
  const owner=useRef<AbortController|null>(null);
  const pending=useRef(false);
  const available=workspaces.filter(item=>item.role!=='viewer');
  useEffect(()=>{const u=new URL(window.location.href);const id=u.searchParams.get('fmsExtensionRequest');const code=u.searchParams.get('fmsExtensionCode');if(id&&/^[0-9A-HJKMNP-TV-Z]{26}$/.test(id)&&code&&/^[A-F0-9]{8}$/.test(code))setRequest({id,code});return()=>owner.current?.abort();},[]);
  useEffect(()=>{if(!target&&available.length)setTarget(available.find(item=>item.id===workspace)?.id??available[0]!.id);},[target,workspace,workspaces]);
  const close=()=>{if(pending.current)return;setRequest(null);const u=new URL(window.location.href);u.searchParams.delete('fmsExtensionRequest');u.searchParams.delete('fmsExtensionCode');window.history.replaceState(window.history.state,'',u);};
  const approve=async()=>{
    if(!request||!target||!transport.integrations?.approveExtension||pending.current)return;
    pending.current=true;setBusy(true);setError('');
    const controller=new AbortController();owner.current=controller;
    try{await transport.integrations.approveExtension(target,request,controller.signal);if(!controller.signal.aborted)setDone(true);}
    catch(failure){if(!controller.signal.aborted){const status=failure&&typeof failure==='object'&&'status' in failure?failure.status:0;setError(status===403?t.captureForbidden:status===409?t.captureExpired:status===401||status===419?t.captureSession:status===429?t.captureThrottled:status===423?t.captureLocked:t.captureFailed);}}
    finally{pending.current=false;if(!controller.signal.aborted)setBusy(false);}
  };
  if(!request)return null;
  return <Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open)close();}} isDismissable={!busy} UNSTABLE_portalContainer={portal}>
    <Modal.Container placement="center" size="sm"><Modal.Dialog className="fms-capture-approval"><Modal.CloseTrigger isDisabled={busy}/>
      <Modal.Header><Modal.Heading>{t.captureTitle}</Modal.Heading></Modal.Header>
      <Modal.Body className="fms-capture-approval-body">
        {done?<Alert status="success"><Alert.Indicator/><Alert.Content><Alert.Title>{t.captureConnected}</Alert.Title><Alert.Description>{t.captureReturn}</Alert.Description></Alert.Content></Alert>:<>
          <p className="fms-capture-intro">{t.captureCompare}</p>
          <div className="fms-capture-verification"><strong>{request.code}</strong><span>{window.location.host}</span></div>
          <Select value={target||null} onChange={key=>{setTarget(String(key??''));setError('');}} variant="secondary" isDisabled={busy||!available.length} placeholder={t.chooseWorkspace}>
            <Label>{t.workspace}</Label><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
            <Select.Popover UNSTABLE_portalContainer={portal}><ListBox>{available.map(item=><ListBox.Item key={item.id} id={item.id} textValue={item.name}><Label>{item.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover>
          </Select>
          <div className="fms-capture-permissions"><h3>{t.captureAccess}</h3><p>{t.captureAllowed}</p><p>{t.captureRestricted}</p></div>
        </>}
        {error&&<Alert className="fms-capture-error" status="danger" role="alert"><Alert.Indicator/><Alert.Content><Alert.Title>{t.captureErrorTitle}</Alert.Title><Alert.Description>{error}</Alert.Description></Alert.Content></Alert>}
      </Modal.Body>
      <Modal.Footer>{done?<Button onPress={close}>{t.captureDone}</Button>:<><Button variant="secondary" isDisabled={busy} onPress={close}>{t.cancel}</Button><Button isDisabled={!available.some(item=>item.id===target)||busy||!transport.integrations?.approveExtension} isPending={busy} onPress={()=>void approve()}>{t.captureApprove}</Button></>}</Modal.Footer>
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
