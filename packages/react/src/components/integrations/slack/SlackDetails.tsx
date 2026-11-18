import {Breadcrumbs, Button, Card, Chip, Label, ListBox, Select, Spinner} from '@heroui/react';
import {CheckmarkCircle02Icon, SlackIcon} from '@hugeicons/core-free-icons';
import {HugeiconsIcon} from '@hugeicons/react';
import type {Workspace} from '../../../contracts';
import type {IntegrationOverview} from '../../../integrations';
import type en from '../locales/en';

export function SlackDetails({info,error,workspace,t,locale,onDetail,onEdit,onDisconnect,workspaces,onWorkspace,portal,connecting,onConnect}:{info:IntegrationOverview|null;error:string;workspace:string|undefined;t:Record<keyof typeof en,string>;locale:string;onDetail:(value:null)=>void;onEdit:()=>void;onDisconnect:()=>void;workspaces:Workspace[];onWorkspace:(id:string)=>void;portal:HTMLDivElement;connecting:boolean;onConnect:()=>void}) {
  const status=info?.slack.connected?(info.slack.enabled?t.configured:t.paused):t.notConnected;
  const issue=info&&['access_revoked','temporarily_unavailable','connection_failed','webhook_rejected','invalid_webhook'].includes(info.slack.status)?t[info.slack.status as 'access_revoked']:null;
  return <>
      <Breadcrumbs aria-label={t.browse}><Breadcrumbs.Item onPress={()=>onDetail(null)}>{t.browse}</Breadcrumbs.Item><Breadcrumbs.Item>{t.slack}</Breadcrumbs.Item></Breadcrumbs>
      <div className="fms-integration-hero"><div className="fms-integration-brand is-slack"><HugeiconsIcon icon={SlackIcon} size={72} strokeWidth={1.5}/></div><div><div className="fms-integration-title"><h2>{t.slack}</h2>{info&&<Chip size="sm" variant="secondary">{status}</Chip>}</div><p>{t.slackIntro}</p>
        <div className="fms-slack-connect">
          {workspaces.length>1?<Select aria-label={t.workspace} value={workspace??null} onChange={id=>{if(typeof id==='string')onWorkspace(id);}} isDisabled={connecting}><Label>{t.workspace}</Label><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger><Select.Popover UNSTABLE_portalContainer={portal}><ListBox>{workspaces.map(item=><ListBox.Item key={item.id} id={item.id} textValue={item.name}><Label>{item.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover></Select>:<span className="fms-integration-workspace">{workspaces[0]?.name}</span>}
          <Button isDisabled={!info?.available||!info.canManage||connecting||(!info.slack.connected&&!info.oauthConfigured)} isPending={connecting} onPress={info?.slack.connected?onEdit:onConnect}>{info?.slack.connected?t.manage:t.connect}</Button>
        </div>
      </div></div>
      {info?.available&&!info.oauthConfigured&&<p className="fms-integration-message">{t.oauthNotConfigured}</p>}
      {info&&!info.available&&<p className="fms-integration-message">{t.serverOff}</p>}{info&&!info.canManage&&<p className="fms-integration-message">{t.readonly}</p>}
      {issue&&<div className="fms-integration-message" role="status"><strong>{t.warning}</strong><p>{issue}</p></div>}
      {workspace&&!info&&!error&&<div role="status" className="fms-integration-loading"><Spinner size="sm"/><span>{t.status}</span></div>}
      {info?.slack.connected&&<Card><Card.Content className="fms-integration-connection"><div><span>{t.status}</span><strong>{info.slack.teamName?info.slack.teamName+' · ':''}{info.slack.channel}</strong><small>{status}</small></div><div><span>{t.activity}</span><strong>{info.slack.lastDeliveredAt?new Intl.DateTimeFormat(locale,{dateStyle:'medium',timeStyle:'short'}).format(new Date(info.slack.lastDeliveredAt)):t.never}</strong></div>{info.canManage&&<Button size="sm" variant="danger-soft" onPress={onDisconnect}>{t.disconnect}</Button>}</Card.Content></Card>}
      <div className="fms-integration-explainer"><h3>{t.how}</h3><p>{t.howHint}</p><p>{t.connectedHint}</p></div>
      <div className="fms-integration-explainer"><h3>{t.eventsTitle}</h3><p>{t.eventsHint}</p><div className="fms-integration-features">{(info?.events??['uploaded','guest_uploaded','processed','trashed','restored'] as const).map(event=><div key={event}><HugeiconsIcon icon={CheckmarkCircle02Icon} size={20} strokeWidth={1.5}/><span>{t[event]}</span></div>)}</div></div>
      <div className="fms-integration-explainer"><h3>{t.steps}</h3><ol className="fms-integration-steps">{(['One','Two','Three'] as const).map((step,index)=><li key={step}><span>{index+1}</span><div><h4>{t[`step${step}`]}</h4><p>{t[`step${step}Hint`]}</p></div></li>)}</ol></div>
  </>;
}
