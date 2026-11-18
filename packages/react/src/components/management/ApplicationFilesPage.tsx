import {Breadcrumbs, Button, Spinner, Select, ListBox, Label} from '@heroui/react';
import {useEffect, useMemo, useState, type ReactNode} from 'react';
import type {BrowseQuery, Entry, StudioTransport} from '../../contracts';
import {applicationLibraryTransport, type ApplicationFilesTransport, type ApplicationSource, type LibraryScope} from '../../application-files';
import {errorText} from '../../ui';
import {useStudioLocalization} from '../../workspace-i18n';

export type ApplicationLibraryProps = {
  scope: LibraryScope; transport: StudioTransport; query: BrowseQuery;
  onNavigate: (entry: Entry) => void; toolSlot: HTMLDivElement | null; titleCountSlot: HTMLSpanElement | null;
};

export function ApplicationFilesPage({transport, portal, locale, sourceId, folderId, search, onNavigate, renderLibrary}: {
  transport: ApplicationFilesTransport; portal:HTMLDivElement; locale:string; sourceId: string | null; folderId: string | null;
  search: string; onNavigate: (source: string | null, folder: string | null) => void;
  renderLibrary: (props: ApplicationLibraryProps) => ReactNode;
}) {
  const {messages:t}=useStudioLocalization();
  const [catalog, setCatalog] = useState<{items:ApplicationSource[];canDownload:boolean}|null>(null);
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [trail, setTrail] = useState<{id:string;name:string}[]>([]);
  const [tools, setTools] = useState<HTMLDivElement|null>(null);
  const [count, setCount] = useState<HTMLSpanElement|null>(null);
  const source = catalog?.items.find(item => item.id === sourceId);
  useEffect(() => {
    const controller = new AbortController();
    setError(''); setCatalog(null);
    void transport.sources(controller.signal).then(value => {if (!controller.signal.aborted) setCatalog({items:value.enabled?value.items:[],canDownload:value.canDownload});})
      .catch(reason => {if (!controller.signal.aborted) setError(errorText(reason));});
    return () => controller.abort();
  }, [transport, attempt]);
  useEffect(() => {
    if (!folderId) {if(trail.length)setTrail([]);return;}
    if (!catalog || trail.at(-1)?.id === folderId) return;
    const controller = new AbortController();
    void transport.details(sourceId??'',folderId,controller.signal).then(folder => {if(!controller.signal.aborted)setTrail([{id:folder.id,name:folder.name}]);})
      .catch(reason => {if(!controller.signal.aborted)setError(errorText(reason));});
    return () => controller.abort();
  }, [transport, catalog, sourceId, folderId, trail]);
  const adapter = useMemo(() => applicationLibraryTransport(transport,sourceId),[transport,sourceId]);
  const scope = useMemo<LibraryScope>(() => ({kind:'application',id:'application',name:t.applicationFiles,role:'viewer',commentsEnabled:false,
    permissions:{write:false,'files.download':catalog?.canDownload===true,'files.trash':false,'uploads.create':false,'shares.manage':false,'files.update':false,'comments.view':false}}),[t.applicationFiles,catalog?.canDownload]);
  const query = useMemo<BrowseQuery>(() => ({view:'files',parent:folderId,search}), [folderId,search]);
  return <>
    <div className="fms-library-header">
      <div className="fms-title-row"><div><div className="fms-title-heading"><h1>{folderId?trail.at(-1)?.name??t.applicationFiles:t.applicationFiles}</h1><span ref={setCount} className="fms-title-count"/></div>
        {folderId&&<Breadcrumbs className="fms-breadcrumbs" aria-label={t.applicationFiles}>
          <Breadcrumbs.Item onPress={() => {setTrail([]);onNavigate(source?.id??null,null);}}>{t.applicationFiles}</Breadcrumbs.Item>
          {source&&<Breadcrumbs.Item onPress={() => {setTrail([]);onNavigate(source.id,null);}}>{source.name}</Breadcrumbs.Item>}
          {folderId&&(trail.at(-1)?.id===folderId?trail:[{id:folderId,name:t.folder}]).map((folder,index)=><Breadcrumbs.Item key={folder.id} onPress={()=>{setTrail(current=>current.slice(0,index+1));onNavigate(source?.id??null,folder.id);}}>{folder.name}</Breadcrumbs.Item>)}
        </Breadcrumbs>}</div></div>
      <div className="fms-toolbar"><div className="fms-toolbar-filters">
        <Select className="fms-application-source-filter" aria-label={t.applicationSourceFilter} variant="secondary" value={sourceId??'all'} isDisabled={!catalog}
          onChange={key=>{if(key==='all'||catalog?.items.some(item=>item.id===key)){setTrail([]);onNavigate(key==='all'?null:String(key),null);}}}>
          <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
          <Select.Popover UNSTABLE_portalContainer={portal}><ListBox><ListBox.Item id="all" textValue={t.applicationAllSources}><Label>{t.applicationAllSources}</Label><ListBox.ItemIndicator/></ListBox.Item>
            {(catalog?.items??[]).map(item=><ListBox.Item key={item.id} id={item.id} textValue={item.name}><Label>{item.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover>
        </Select>
      </div><div className="fms-toolbar-view" ref={setTools}/></div>
    </div>
    {error?<div className="fms-centered" role="alert"><p>{error}</p><Button size="sm" onPress={()=>setAttempt(value=>value+1)}>{t.retry}</Button></div>:catalog===null?<div className="fms-centered"><Spinner aria-label={t.loading}/></div>:sourceId&&!source?<div className="fms-centered"><p>{sourceId?t.applicationSourceUnavailable:t.applicationFilesEmpty}</p></div>:adapter&&scope&&renderLibrary({scope,transport:adapter,query,toolSlot:tools,titleCountSlot:count,
      onNavigate:entry=>{setTrail(current=>[...current,{id:entry.id,name:entry.name}]);onNavigate(sourceId,entry.id);}})}
  </>;
}
