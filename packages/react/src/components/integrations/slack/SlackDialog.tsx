import {Button, Checkbox, Label, Modal, Spinner, Switch} from '@heroui/react';
import {useEffect, useRef, useState} from 'react';
import type {IntegrationOverview, IntegrationsTransport, SlackEvent} from '../../../integrations';
import type en from '../locales/en';

type Messages = Record<keyof typeof en, string>;
export function SlackDialog({workspace, info, transport, portal, t, onSaved, onClose, onReconnect}: {workspace:string; info:IntegrationOverview; transport:IntegrationsTransport; portal:HTMLDivElement; t:Messages; onSaved:(info:IntegrationOverview)=>void; onClose:()=>void;onReconnect:()=>void}) {
  const [events,setEvents]=useState<SlackEvent[]>(info.slack.events);
  const [enabled,setEnabled]=useState(info.slack.enabled);
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState('');
  const owner=useRef(new AbortController());
  const pending=useRef(false);
  useEffect(()=>{owner.current=new AbortController();return()=>owner.current.abort();},[]);
  const submit=async()=>{
    if(pending.current)return;pending.current=true;setBusy(true);setError('');
    try {
      const saved=await transport.saveSlack(workspace,{revision:info.slack.revision,events,enabled},owner.current.signal);
      if(!owner.current.signal.aborted){onSaved(saved);onClose();}
    } catch {if(!owner.current.signal.aborted)setError(t.saveFailed);}
    finally {pending.current=false;if(!owner.current.signal.aborted)setBusy(false);}
  };
  return <Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open&&!busy)onClose();}} isDismissable={!busy} UNSTABLE_portalContainer={portal}>
    <Modal.Container placement="center" size="md" scroll="inside"><Modal.Dialog>
      <Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{t.manage}</Modal.Heading></Modal.Header>
      <Modal.Body><form id="fms-slack-form" className="fms-integration-form" onSubmit={event=>{event.preventDefault();void submit();}}>
        <div className="fms-slack-summary"><span>{t.slackDestination}</span><strong>{info.slack.teamName?info.slack.teamName+' · ':''}{info.slack.channel}</strong><Button size="sm" variant="tertiary" isDisabled={busy||!info.oauthConfigured} onPress={onReconnect}>{t.reconnect}</Button></div>
        <fieldset className="fms-integration-events"><legend>{t.eventsTitle}</legend>{info.events.map(event=><Checkbox key={event} isSelected={events.includes(event)} isDisabled={busy} onChange={selected=>setEvents(current=>selected?[...current,event]:current.filter(value=>value!==event))}><Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Label>{t[event]}</Label></Checkbox.Content></Checkbox>)}</fieldset>
        <Switch isSelected={enabled} onChange={setEnabled} isDisabled={busy}><Switch.Content><Switch.Control><Switch.Thumb/></Switch.Control><Label>{t.enabled}</Label></Switch.Content></Switch>
        {error&&<p role="alert">{error}</p>}
      </form></Modal.Body>
      <Modal.Footer><Button variant="secondary" isDisabled={busy} onPress={onClose}>{t.cancel}</Button><Button type="submit" form="fms-slack-form" isDisabled={busy||events.length===0} isPending={busy}>{({isPending})=><>{isPending&&<Spinner size="sm" color="current"/>}{t.save}</>}</Button></Modal.Footer>
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
