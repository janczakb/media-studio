import {TableEmptyState} from '../TableEmptyState';
import {Button, Card, Input, InputGroup, Label, ListBox, Modal, Select, Spinner, Table, Tabs, TextField} from '@heroui/react';
import {Copy01Icon, Globe02Icon, RefreshIcon} from '@hugeicons/core-free-icons';
import {HugeiconsIcon} from '@hugeicons/react';
import {useEffect, useRef, useState} from 'react';
import type {ApiAccessOverview, ApiAccessTransport, ApiTokenRecord} from '../../api-access';
import {ApiUsageChart} from './ApiUsageChart';
import type en from './locales/en';

type Messages=Record<keyof typeof en,string>;
export function ApiAccessPanel({workspace,transport,t,portal,locale,copy}:{workspace:string;transport:ApiAccessTransport;t:Messages;portal:HTMLDivElement;locale:string;copy:(value:string)=>Promise<void>}) {
  const [info,setInfo]=useState<ApiAccessOverview|null>(null);
  const [error,setError]=useState('');
  const [refresh,setRefresh]=useState(0);
  const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);
  const [create,setCreate]=useState(false);
  const [selectPortal,setSelectPortal]=useState<HTMLDivElement|null>(null);
  const [revoke,setRevoke]=useState<ApiTokenRecord|null>(null);
  const [secret,setSecret]=useState('');
  const [name,setName]=useState('');
  const [scope,setScope]=useState<'read'|'write'>('read');
  const [days,setDays]=useState('30');
  const [sample,setSample]=useState('curl');
  const codeRef=useRef<HTMLDivElement>(null);
  const owner=useRef(new AbortController());
  useEffect(()=>{owner.current=new AbortController();return()=>{owner.current.abort();};},[]);
  useEffect(()=>{const controller=new AbortController();setLoading(true);setError('');transport.overview(workspace,controller.signal).then(setInfo).catch(()=>{if(!controller.signal.aborted)setError(t.apiUnexpected);}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});return()=>controller.abort();},[transport,workspace,refresh,t]);
  const mutate=async(action:()=>Promise<void>)=>{if(busy)return;setBusy(true);setError('');try{await action();if(!owner.current.signal.aborted)setRefresh(v=>v+1);}catch(e){if(!owner.current.signal.aborted)setError(e instanceof Error?e.message:t.apiUnexpected);}finally{if(!owner.current.signal.aborted)setBusy(false);}};
  if(!info)return <div role={error?'alert':'status'}>{error?<><p>{error}</p><Button onPress={()=>setRefresh(v=>v+1)}>{t.retry}</Button></>:<Spinner/>}</div>;
  const totals=info.usage.reduce((v,p)=>({requests:v.requests+p.requests,errors:v.errors+p.errors}),{requests:0,errors:0});
  const date=(s:string|null)=>s?new Intl.DateTimeFormat(locale,{dateStyle:'medium'}).format(new Date(s)):t.apiNever;
  const endpoint=info.endpoint+'/workspaces/'+encodeURIComponent(workspace)+'/entries?limit=20';
  const examples={curl:`curl '${endpoint}' \\\n  -H "Authorization: Bearer $STUDIO_API_TOKEN" \\\n  -H 'Accept: application/json'`,typescript:`import {createStudioApi} from '@useflex/media-studio/api';\n\nconst studio = createStudioApi({\n  baseUrl: '${info.endpoint}',\n  workspace: '${workspace}',\n  token: () => process.env.STUDIO_API_TOKEN!,\n});\nconst page = await studio.files({limit: 20});\n// Next page: studio.files({cursor: page.nextCursor});`};
  return <>
    <div className="fms-ai-intro"><h2>{t.apiTitle}</h2><p>{t.apiHint}</p></div>
    {!info.enabled&&<p role="status">{t.apiOff}</p>}
    <Card className="fms-api-card"><Card.Content>
      <div className="fms-api-summary"><div className="fms-api-metrics"><div><strong>{info.items.filter(i=>i.status==='active').length}</strong><span>{t.apiTokens}</span></div><div><strong>{totals.requests.toLocaleString(locale)}</strong><span>{t.apiRequests}</span></div></div><div className="fms-api-actions"><Button size="sm" variant="outline" onPress={()=>codeRef.current?.scrollIntoView({block:'nearest',behavior:'smooth'})}>{t.apiDocs}</Button>{info.canManage&&<Button size="sm" isDisabled={!info.enabled||busy} onPress={()=>{setName('');setScope('read');setDays(String(Math.min(30,info.maxDays)));setCreate(true);}}>{t.apiCreate}</Button>}</div></div>
      <p className="fms-api-muted">{t.apiWorkspaceHint}</p>
      <div className="fms-api-token-table"><Table><Table.ScrollContainer><Table.Content aria-label={t.apiTokens}><Table.Header><Table.Column isRowHeader>{t.apiToken}</Table.Column><Table.Column>{t.apiScope}</Table.Column><Table.Column>{t.apiStatus}</Table.Column><Table.Column>{t.apiExpires}</Table.Column><Table.Column>{t.apiLastUsed}</Table.Column><Table.Column aria-label={t.apiRevoke}/></Table.Header><Table.Body renderEmptyState={()=><TableEmptyState kind="tokens" label={t.apiNoTokens}/>}>{info.items.map(item=><Table.Row id={item.id} key={item.id}><Table.Cell><strong>{item.name}</strong><code>•••• {item.suffix}</code></Table.Cell><Table.Cell>{item.scope==='read'?t.apiRead:t.apiWrite}</Table.Cell><Table.Cell>{item.status==='active'?t.apiActive:item.status==='expired'?t.apiExpired:t.apiRevoked}</Table.Cell><Table.Cell>{date(item.expiresAt)}</Table.Cell><Table.Cell>{date(item.lastUsedAt)}</Table.Cell><Table.Cell>{info.canManage&&item.status==='active'&&<Button size="sm" variant="ghost" isDisabled={busy} onPress={()=>setRevoke(item)}>{t.apiRevoke}</Button>}</Table.Cell></Table.Row>)}</Table.Body></Table.Content></Table.ScrollContainer></Table></div>
      <div className="fms-api-chart-heading"><div><h3>{t.apiUsage}</h3><p className="fms-api-muted">{t.apiUsageHint}</p></div><Button isIconOnly variant="ghost" size="sm" aria-label={t.apiRefresh} isDisabled={loading||busy} onPress={()=>setRefresh(v=>v+1)}><HugeiconsIcon icon={RefreshIcon} size={18} strokeWidth={2}/></Button></div>
      <ApiUsageChart points={info.usage} t={t} portal={portal} locale={locale}/>
      <p className="fms-api-muted">{t.apiLimit}: {info.requestsPerMinute}</p>
    </Card.Content></Card>
    {error&&<p role="alert">{error}</p>}
    <div className="fms-api-sdk" ref={codeRef}><div className="fms-ai-intro"><h2>{t.apiSdkTitle}</h2><p>{t.apiSdkHint}</p></div>
      <TextField isReadOnly value={info.endpoint}><Label>API URL</Label><InputGroup fullWidth><InputGroup.Prefix><HugeiconsIcon icon={Globe02Icon} size={16}/></InputGroup.Prefix><InputGroup.Input/><InputGroup.Suffix><Button isIconOnly size="sm" variant="ghost" aria-label={t.copy} onPress={()=>void copy(info.endpoint)}><HugeiconsIcon icon={Copy01Icon} size={16}/></Button></InputGroup.Suffix></InputGroup></TextField>
      <Tabs variant="secondary" selectedKey={sample} onSelectionChange={key=>setSample(String(key))}><Tabs.ListContainer><Tabs.List aria-label={t.apiDocs}><Tabs.Tab id="curl">cURL<Tabs.Indicator/></Tabs.Tab><Tabs.Tab id="typescript">TypeScript<Tabs.Indicator/></Tabs.Tab></Tabs.List></Tabs.ListContainer>{Object.entries(examples).map(([key,code])=><Tabs.Panel key={key} id={key}><div className="fms-api-code"><pre><code>{code}</code></pre><Button size="sm" variant="outline" onPress={()=>void copy(code)}>{t.copy}</Button></div></Tabs.Panel>)}</Tabs>
      <p className="fms-api-muted">{t.apiSdkNotPublished}</p>
    </div>
    <Modal><Modal.Backdrop isOpen={create} onOpenChange={value=>{if(!busy)setCreate(value);}} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{t.apiCreate}</Modal.Heading></Modal.Header><Modal.Body ref={setSelectPortal}>
      <TextField value={name} onChange={setName} isRequired><Label>{t.apiName}</Label><Input variant="secondary" maxLength={80} autoComplete="off"/></TextField>
      <Select value={scope} onChange={v=>setScope(v==='write'?'write':'read')} variant="secondary"><Label>{t.apiScope}</Label><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger><Select.Popover {...(selectPortal?{UNSTABLE_portalContainer:selectPortal}:{})}><ListBox><ListBox.Item id="read" textValue={t.apiRead}>{t.apiRead}<ListBox.ItemIndicator/></ListBox.Item><ListBox.Item id="write" textValue={t.apiWrite}>{t.apiWrite}<ListBox.ItemIndicator/></ListBox.Item></ListBox></Select.Popover></Select>
      <Select value={days} onChange={v=>setDays(String(v))} variant="secondary"><Label>{t.apiExpiry}</Label><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger><Select.Popover {...(selectPortal?{UNSTABLE_portalContainer:selectPortal}:{})}><ListBox>{[...new Set([7,30,90,info.maxDays].filter(d=>d<=info.maxDays))].sort((a,b)=>a-b).map(d=><ListBox.Item key={d} id={String(d)} textValue={`${d} ${t.apiDays}`}>{d} {t.apiDays}<ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover></Select>
      {error&&<p role="alert">{error}</p>}
    </Modal.Body><Modal.Footer><Button isDisabled={!name.trim()||busy} isPending={busy} onPress={()=>void mutate(async()=>{const result=await transport.create(workspace,{name:name.trim(),scope,days:Number(days)},owner.current.signal);if(owner.current.signal.aborted)return;setCreate(false);setSecret(result.token);})}>{t.apiCreate}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
    <Modal><Modal.Backdrop isOpen={!!secret} onOpenChange={v=>{if(!v)setSecret('');}} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger/><Modal.Header><Modal.Heading>{t.apiSaveToken}</Modal.Heading></Modal.Header><Modal.Body><p>{t.apiSecretHint}</p><TextField value={secret} isReadOnly><Label>{t.apiToken}</Label><Input variant="secondary" autoComplete="off"/></TextField></Modal.Body><Modal.Footer><Button variant="outline" onPress={()=>void copy(secret)}>{t.apiCopyToken}</Button><Button onPress={()=>setSecret('')}>{t.apiDone}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
    <Modal><Modal.Backdrop isOpen={!!revoke} onOpenChange={v=>{if(!v&&!busy)setRevoke(null);}} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger isDisabled={busy}/><Modal.Header><Modal.Heading>{t.apiConfirm}</Modal.Heading></Modal.Header><Modal.Body><p>{revoke?.name}</p><p>{t.apiRevokeHint}</p>{error&&<p role="alert">{error}</p>}</Modal.Body><Modal.Footer><Button variant="secondary" isDisabled={busy} onPress={()=>setRevoke(null)}>{t.apiCancel}</Button><Button variant="danger" isPending={busy} isDisabled={busy} onPress={()=>void mutate(async()=>{await transport.revoke(workspace,revoke!.id,owner.current.signal);if(!owner.current.signal.aborted)setRevoke(null);})}>{t.apiConfirm}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
  </>;
}
