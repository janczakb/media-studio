import {useStudioNamespace} from '../../workspace-i18n';
import {Button, Card, Chip, Modal, Tabs} from '@heroui/react';
import {AiBrain01Icon, ApiIcon, ArrowUpRight01Icon, SlackIcon} from '@hugeicons/core-free-icons';
import {HugeiconsIcon} from '@hugeicons/react';
import {useEffect, useRef, useState} from 'react';
import type {StudioTransport, Workspace} from '../../contracts';
import type {IntegrationOverview} from '../../integrations';
import {SlackDialog} from './slack/SlackDialog';
import {SlackDetails} from './slack/SlackDetails';
import {BrowserExtensionApproval} from './BrowserExtensionApproval';

export function IntegrationsPage({transport,workspace,workspaces,locale,portal,search,detail,onDetail,onAi,onWorkspace}: {
  transport:StudioTransport;workspace:string|undefined;workspaces:Workspace[];locale:string;portal:HTMLDivElement;search:string;detail:'slack'|null;
  onDetail:(value:'slack'|null)=>void;onAi:(tab:'api'|'connection')=>void;onWorkspace:(id:string)=>void;
}) {
  const t=useStudioNamespace('integrations');
  const [category,setCategory]=useState('all');
  const [info,setInfo]=useState<IntegrationOverview|null>(null);
  const [error,setError]=useState('');
  const [attempt,setAttempt]=useState(0);
  const [connecting,setConnecting]=useState(false);
  const [edit,setEdit]=useState(false);
  const [confirm,setConfirm]=useState(false);
  const [busy,setBusy]=useState(false);
  const [notice,setNotice]=useState('');
  const owner=useRef(new AbortController());
  const pending=useRef(false);
  useEffect(()=>{owner.current=new AbortController();return()=>owner.current.abort();},[]);
  useEffect(()=>{
    setInfo(null);setError('');if(!workspace||!transport.integrations)return;
    const controller=new AbortController();transport.integrations.overview(workspace,controller.signal).then(value=>{if(!controller.signal.aborted)setInfo(value);})
      .catch(()=>{if(!controller.signal.aborted)setError(t.loadFailed);});return()=>controller.abort();
  },[workspace,transport.integrations,attempt,t.loadFailed]);
  const disconnect=async()=>{
    if(!workspace||!info||!transport.integrations||pending.current)return;
    pending.current=true;setBusy(true);setError('');
    try{const value=await transport.integrations.disconnectSlack(workspace,info.slack.revision,owner.current.signal);if(!owner.current.signal.aborted){setInfo(value);setConfirm(false);setNotice(t.disconnected);}}
    catch{if(!owner.current.signal.aborted)setError(t.saveFailed);}finally{pending.current=false;if(!owner.current.signal.aborted)setBusy(false);}
  };
  const connect=async()=>{
    if(!workspace||!info?.oauthConfigured||!transport.integrations||pending.current)return;
    pending.current=true;setConnecting(true);setError('');
    try {
      const result=await transport.integrations.startSlackOAuth(workspace,{revision:info.slack.revision,returnUrl:window.location.href},owner.current.signal);
      if(owner.current.signal.aborted)return;
      const destination=new URL(result.authorizationUrl);
      if(!['https://slack.com','https://slack-gov.com'].includes(destination.origin)||destination.pathname!=='/oauth/v2/authorize')throw new Error('Unexpected authorization address');
      window.location.assign(destination.href);
    } catch {if(!owner.current.signal.aborted)setError(t.oauthFailed);}
    finally {pending.current=false;if(!owner.current.signal.aborted)setConnecting(false);}
  };
  useEffect(()=>{
    const url=new URL(window.location.href);const result=url.searchParams.get('fmsSlack');
    if(result){setNotice(result==='connected'?t.oauthConnected:result==='cancelled'?t.oauthCancelled:result==='conflict'?t.oauthConflict:t.oauthFailed);url.searchParams.delete('fmsSlack');window.history.replaceState(window.history.state,'',url);}
  },[t.oauthConnected,t.oauthCancelled,t.oauthConflict,t.oauthFailed]);
  const cards=[
    {id:'slack',name:t.slack,description:t.slackHint,category:'notifications',icon:SlackIcon},
    ...(transport.apiAccess?[{id:'api',name:t.api,description:t.apiHint,category:'developer',icon:ApiIcon}]:[]),
    ...(transport.aiInfo?[{id:'mcp',name:t.mcp,description:t.mcpHint,category:'developer',icon:AiBrain01Icon}]:[]),
  ].filter(card=>(category==='all'||card.category===category)&&`${card.name} ${card.description}`.toLocaleLowerCase(locale).includes(search.toLocaleLowerCase(locale)));
  const status=info?.slack.connected?(info.slack.enabled?t.configured:t.paused):t.notConnected;
  return <section className="fms-integrations-page" aria-label={t.title}><div className="fms-integrations-container">
    <header className="fms-integrations-heading"><div><h1>{t.title}</h1><p>{t.subtitle}</p></div>
    </header>
    {error&&<div className="fms-integration-message" role="alert"><p>{error}</p><Button size="sm" variant="secondary" onPress={()=>setAttempt(n=>n+1)}>{t.retry}</Button></div>}
    {!workspace&&<p>{t.chooseWorkspace}</p>}
    {detail==='slack'?<>
      <SlackDetails info={info} error={error} workspace={workspace} t={t} locale={locale} onDetail={()=>onDetail(null)} onEdit={()=>setEdit(true)} onDisconnect={()=>setConfirm(true)} workspaces={workspaces} onWorkspace={onWorkspace} portal={portal} connecting={connecting} onConnect={()=>void connect()}/>
    </>:<>
      <div className="fms-integrations-section-heading"><h2>{t.apps}</h2><p>{t.appsHint}</p></div>
      <div className="fms-integrations-categories"><Tabs selectedKey={category} onSelectionChange={key=>setCategory(String(key))}><Tabs.ListContainer><Tabs.List aria-label={t.categories}>{(['all','notifications','developer'] as const).map(key=><Tabs.Tab id={key} key={key}><span className="fms-integration-category-label">{t[key]}</span><Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer></Tabs></div>
      <div className="fms-integrations-grid">{cards.map(card=><Card key={card.id} className="fms-integration-tile"><Card.Content className="fms-integration-card"><div className={`fms-integration-card-icon is-${card.id}`}><HugeiconsIcon icon={card.icon} size={32} strokeWidth={1.5}/></div><div><h3>{card.name}</h3><p>{card.description}</p>{card.id==='slack'&&info?.slack.connected&&<Chip size="sm" variant="secondary">{status}</Chip>}<Button size="sm" variant="tertiary" aria-label={`${t.open}: ${card.name}`} onPress={()=>card.id==='slack'?onDetail('slack'):onAi(card.id==='api'?'api':'connection')}>{t.open}<HugeiconsIcon icon={ArrowUpRight01Icon} size={16}/></Button></div></Card.Content></Card>)}</div>{!cards.length&&<p>{t.noResults}</p>}
    </>}
    <BrowserExtensionApproval transport={transport} workspaces={workspaces} workspace={workspace} locale={locale} portal={portal}/><p className="fms-integration-announcement" role="status">{notice}</p>
    {edit&&info&&workspace&&transport.integrations&&<SlackDialog workspace={workspace} info={info} transport={transport.integrations} portal={portal} t={t} onSaved={value=>{setInfo(value);setNotice(t.saved);}} onClose={()=>setEdit(false)} onReconnect={()=>{setEdit(false);void connect();}}/>}
    <Modal><Modal.Backdrop isOpen={confirm} onOpenChange={open=>{if(!busy)setConfirm(open);}} isDismissable={!busy} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{t.disconnectTitle}</Modal.Heading></Modal.Header><Modal.Body><p>{t.disconnectHint}</p></Modal.Body><Modal.Footer><Button variant="secondary" isDisabled={busy} onPress={()=>setConfirm(false)}>{t.cancel}</Button><Button variant="danger" isDisabled={busy} isPending={busy} onPress={()=>void disconnect()}>{t.disconnectConfirm}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
  </div></section>;
}
