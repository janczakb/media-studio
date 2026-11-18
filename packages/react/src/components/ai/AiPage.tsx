import {useStudioNamespace} from '../../workspace-i18n';
import {Button, Modal, Spinner, Tabs} from '@heroui/react';
import {useEffect, useRef, useState} from 'react';
import type {AiConfiguration, StudioTransport} from '../../contracts';
import {AiLabelsPanel} from './AiLabelsPanel';
import {ApiAccessPanel} from './ApiAccessPanel';
import {AiConnection} from './AiConnection';

export function AiPage({info,error,onRetry,locale,portal,workspace,transport,onOpen,initialSection='connection'}:{initialSection?:'api'|'connection';workspace:string|undefined;transport:StudioTransport;onOpen:(id:string)=>void;info:AiConfiguration|null;error:string;onRetry:()=>void;locale:string;portal:HTMLDivElement}) {
  const t=useStudioNamespace('ai');
  const [section,setSection]=useState<string>(initialSection);
  useEffect(()=>setSection(initialSection),[initialSection]);
  const [showTools,setShowTools]=useState(false);
  const [copied,setCopied]=useState<string|null>(null);
  const [copyError,setCopyError]=useState('');
  const timer=useRef<ReturnType<typeof setTimeout>|undefined>(undefined);
  const alive=useRef(true);
  useEffect(()=>{alive.current=true;return ()=>{alive.current=false;clearTimeout(timer.current);};},[]);
  if(!info)return <div className="fms-centered" role={error?'alert':'status'}>{error?<><p>{error}</p><Button onPress={onRetry}>{t.retry}</Button></>:<><Spinner/><span>{t.loading}</span></>}</div>;
  const mcp=info.mcp;
  const hasLabels=!!workspace&&!!transport.aiLabels&&(info.features.imageTags.enabled||info.features.videoAnalysis.enabled);
  const names:Record<string,string>={studio_list_workspaces:t.listWorkspaces,studio_list_files:t.listFiles,studio_get_file:t.getFile,studio_search_files:t.searchFiles};
  const copy=async(value:string)=>{
    try{await navigator.clipboard.writeText(value);if(!alive.current)return;setCopyError('');setCopied(value);clearTimeout(timer.current);timer.current=setTimeout(()=>setCopied(null),2500);}
    catch{if(alive.current)setCopyError(t.copyError);}
  };
  return <section className="fms-ai-page" aria-label={t.title}>
    <div className="fms-ai-heading"><h1>{t.title}</h1></div>
    <Tabs orientation="vertical" selectedKey={section} onSelectionChange={key=>setSection(String(key))} className="fms-ai-sections">
      <Tabs.ListContainer><Tabs.List aria-label={t.title}>
        <Tabs.Tab id="connection">{t.connectMenu}<Tabs.Indicator/></Tabs.Tab>
        <Tabs.Tab id="api">{t.apiMenu}<Tabs.Indicator/></Tabs.Tab>
        <Tabs.Tab id="analysis">{t.features}<Tabs.Indicator/></Tabs.Tab>
        {hasLabels&&<Tabs.Tab id="labels">{t.labels}<Tabs.Indicator/></Tabs.Tab>}
      </Tabs.List></Tabs.ListContainer>
      <Tabs.Panel id="connection" className="fms-ai-panel"><AiConnection mcp={mcp} t={t} portal={portal} copied={copied} copy={copy} onTools={()=>setShowTools(true)}/></Tabs.Panel>
      <Tabs.Panel id="api" className="fms-ai-panel">{section==='api'&&(workspace&&transport.apiAccess&&info.api?.canUse?<ApiAccessPanel key={workspace} workspace={workspace} transport={transport.apiAccess} t={t} locale={locale} portal={portal} copy={copy}/>:<p>{t.apiDenied}</p>)}</Tabs.Panel>
      <Tabs.Panel id="analysis" className="fms-ai-panel"><div className="fms-ai-features"><h2>{t.features}</h2><p>{t.featuresHint}</p><dl>{Object.entries(info.features).filter(([,feature])=>feature.implemented).map(([key,feature])=><div key={key}><dt>{t[key as keyof typeof info.features]}</dt><dd>{feature.enabled?t.enabled:t.off}</dd></div>)}</dl></div></Tabs.Panel>
      {hasLabels&&<Tabs.Panel id="labels" className="fms-ai-panel"><AiLabelsPanel key={workspace} workspace={workspace!} transport={transport.aiLabels!} t={t} onOpen={onOpen}/></Tabs.Panel>}
    </Tabs>
    <span className="fms-ai-sr-status" role="status">{copied?t.copied:''}</span>{copyError&&<p role="alert">{copyError}</p>}
    <Modal><Modal.Backdrop isOpen={showTools} onOpenChange={setShowTools} UNSTABLE_portalContainer={portal}><Modal.Container placement="center" size="sm"><Modal.Dialog><Modal.CloseTrigger/><Modal.Header><Modal.Heading>{t.tools}</Modal.Heading></Modal.Header><Modal.Body><div className="fms-ai-tools">{mcp.tools.length?mcp.tools.map(tool=><div key={tool}><span><strong>{names[tool]??tool}</strong><code>{tool}</code></span><span>{t.readonly}</span></div>):<p>{mcp.authentication==='managed'?t.managedTools:t.noTools}</p>}</div></Modal.Body></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
  </section>;
}
