import {useDateText, useStudioLocale} from '../layout/TimePreferences';
import {Button, Chip, Label, Spinner, Tabs, Tag, TagGroup, TextArea, TextField} from '@heroui/react';
import type {Key} from '@heroui/react';
import {XIcon} from '@hugeicons/core-free-icons';
import {useEffect, useRef, useState} from 'react';
import type {Entry, EntryDetails, EntryTagInput, StudioTransport} from '../../contracts';
import {EntryTagField} from './EntryTagField';
import {FileComments} from './FileComments';
import {Icon,errorText,formatBytes,type Messages} from '../../ui';

export function EntryInspector({entry,workspace,transport,canWrite,onChanged,t,duration,comments,portal,tagPortal,activeTab,onTabChange,hidden=false}:{entry:Entry;workspace:string;transport:StudioTransport;canWrite:boolean;onChanged:(entry:Entry)=>void;t:Messages;duration?: number | undefined;comments?:boolean;portal:HTMLDivElement;tagPortal?:HTMLDivElement;hidden?:boolean;activeTab?:string;onTabChange?:(tab:string)=>void}) {
  const formatDate=useDateText();
  const locale = useStudioLocale();
  const [localTab,setLocalTab]=useState('details');
  const [commentControls,setCommentControls]=useState<HTMLDivElement|null>(null);
  const tab=activeTab??localTab;
  const setTab=(value:string)=>{setLocalTab(value);onTabChange?.(value);};
  const showComments=comments!==false&&!!transport.comments&&!entry.trashedAt;
  const [details,setDetails]=useState<EntryDetails|null>(null);
  const [alt,setAlt]=useState(''),[caption,setCaption]=useState('');
  const [error,setError]=useState(''),[busy,setBusy]=useState(false),[saved,setSaved]=useState(false),[attempt,retry]=useState(0);
  const [tagDraft, setTagDraft] = useState('');
  const mutation = useRef<AbortController | null>(null);
  const localTagRevision = useRef<number | null>(null);
  useEffect(()=>{
    if (localTagRevision.current === entry.revision) {
      localTagRevision.current = null;
      return;
    }
    const controller=new AbortController();setDetails(null);setError('');setSaved(false);
    void transport.details(workspace,entry.id,controller.signal).then(value=>{if(controller.signal.aborted)return;setDetails(value);setAlt(value.metadata.alt??'');setCaption(value.metadata.caption??'');}).catch(e=>{if(!controller.signal.aborted)setError(errorText(e));});
    return()=>controller.abort();
  },[entry.id,entry.revision,workspace,transport,attempt]);
  useEffect(() => () => mutation.current?.abort(), [entry.id, workspace, transport]);

  const originalMimeType = details?.mimeType ?? entry.mimeType;
  const mediaFamily = originalMimeType?.split('/', 1)[0];
  const renditions = details?.renditions?.filter(({mimeType}) => {
    if (mediaFamily === 'image' || mediaFamily === 'video' || mediaFamily === 'audio') {
      return mimeType.startsWith(mediaFamily + '/');
    }
    return mimeType === originalMimeType;
  });
  const largestRendition = renditions?.length ? renditions.reduce((largest, candidate) => {
    const largestPixels = (largest.width ?? 0) * (largest.height ?? 0);
    const candidatePixels = (candidate.width ?? 0) * (candidate.height ?? 0);
    return candidatePixels > largestPixels || (candidatePixels === largestPixels && candidate.bytes > largest.bytes)
      ? candidate : largest;
  }) : undefined;
  const originalSize = details?.size ?? entry.size;
  const renditionRatio = largestRendition && originalSize !== null && originalSize > 0
    ? largestRendition.bytes / originalSize : null;
  const renditionDifference = largestRendition && originalSize !== null
    ? originalSize - largestRendition.bytes : 0;
  const tags = details?.tags ?? [];
  const canEditTags = !!details?.canEditTags && typeof transport.replaceTags === 'function';
  async function mutate(action: (signal: AbortSignal) => Promise<void>) {
    if (mutation.current) return;
    const controller = new AbortController();
    mutation.current = controller;
    setBusy(true);
    setError('');
    try {
      await action(controller.signal);
    } catch (reason) {
      if (!controller.signal.aborted) setError(errorText(reason));
    } finally {
      if (mutation.current === controller) mutation.current = null;
      if (!controller.signal.aborted) setBusy(false);
    }
  }

  function saveTags(next: EntryTagInput[], clearDraft = false) {
    const replace = transport.replaceTags;
    if (!details || !canEditTags || !replace) return;
    void mutate(async signal => {
      const result = await replace(workspace, entry.id, details.revision, next, signal);
      if (signal.aborted) return;
      const updatedAt = result.updatedAt ?? details.updatedAt;
      setDetails({...details, ...result, updatedAt});
      if (clearDraft) setTagDraft('');
      if (result.revision !== entry.revision) localTagRevision.current = result.revision;
      onChanged({...entry, revision: result.revision, updatedAt});
    });
  }

  return <section hidden={hidden} inert={hidden} className={`fms-inspector${showComments&&tab==='comments'?' is-comments':''}`} aria-label={t.details}>
    <header className="fms-inspector-header">{showComments?<Tabs variant="secondary" selectedKey={tab} onSelectionChange={key=>setTab(String(key))}><Tabs.ListContainer><Tabs.List aria-label={t.details}><Tabs.Tab id="details">{t.details}<Tabs.Indicator/></Tabs.Tab><Tabs.Tab id="comments">{t.comments}<Tabs.Indicator/></Tabs.Tab></Tabs.List></Tabs.ListContainer></Tabs>:<h2>{t.details}</h2>}{showComments&&tab==='comments'&&<div ref={setCommentControls} className="fms-comment-controls"/>}</header>
    <div hidden={tab!=='details'&&showComments}>
    <dl className="fms-file-facts">
      {details?.parentName && <div><dt>{t.filePath}</dt><dd>{details.parentName}</dd></div>}
      <div><dt>{t.fileSize}</dt><dd>{formatBytes(entry.size)}</dd></div>
      <div><dt>{t.fileType}</dt><dd>{entry.mimeType ?? t.folder}</dd></div>
      {(details?.createdAt ?? entry.createdAt) && <div><dt>{t.fileCreated}</dt><dd>{formatDate(details?.createdAt ?? entry.createdAt!)}</dd></div>}
      <div><dt>{t.modified}</dt><dd>{formatDate(entry.updatedAt)}</dd></div>
    </dl>
    {((entry.width && entry.height) || duration) ? <dl className="fms-file-facts fms-inspector-media-facts">
      {!!entry.width && !!entry.height && <>
        <div><dt>{t.fileDimensions}</dt><dd>{entry.width} × {entry.height}</dd></div>
        <div><dt>{t.fileAspectRatio}</dt><dd>{aspectRatio(entry.width, entry.height)}</dd></div>
      </>}
      {!!duration && <div><dt>{t.fileDuration}</dt><dd>{Math.floor(duration / 60)}:{String(Math.floor(duration % 60)).padStart(2, '0')}</dd></div>}
    </dl> : null}
    {largestRendition && <section className="fms-rendition-facts" aria-label={t.conversions}>
      <h3>{t.conversions}</h3>
      <div className="fms-rendition-summary" data-smaller={renditionDifference > 0}>
        <dl className="fms-rendition-comparison">
          <div>
            <dt>{t.conversionBefore}</dt>
            <dd>{formatBytes(originalSize, locale)}</dd>
          </div>
          <div>
            <dt>{t.conversionAfter}</dt>
            <dd>{formatBytes(largestRendition.bytes, locale)}</dd>
          </div>
        </dl>
        {renditionRatio !== null && <>
          <div className="fms-rendition-track" aria-hidden="true">
            <div style={{inlineSize: `${Math.min(1, renditionRatio) * 100}%`}}/>
          </div>
          <div className="fms-rendition-caption">
            <span>{(renditionDifference >= 0 ? t.conversionSaved : t.conversionIncrease)
              .replace('{size}', formatBytes(Math.abs(renditionDifference), locale))}</span>
            <span>{t.conversionOriginalShare.replace('{percent}',
              new Intl.NumberFormat(locale, {style: 'percent', maximumFractionDigits: 1}).format(renditionRatio))}</span>
          </div>
        </>}
      </div>
    </section>}
    {details && <>
      {(tags.length > 0 || canEditTags) && <section className="fms-inspector-insights">
        <h3>{t.tags}</h3>
        {tags.length > 0 && <TagGroup className="fms-inspector-manual-tags" aria-label={t.tags} size="sm"
          {...(canEditTags ? {onRemove: (keys: Set<Key>) => saveTags(tags.filter(tag => !keys.has(tag.id)).map(({id}) => ({id})))} : {})}>
          <TagGroup.List>
            {tags.map(tag => <Tag key={tag.id} id={tag.id} textValue={tag.label}>
              <span lang={tag.locale} dir="auto">{tag.label}</span>
              {canEditTags && <Tag.RemoveButton isDisabled={busy} aria-label={t.removeFileTag}>
                <Icon icon={XIcon} size={11} strokeWidth={2}/>
              </Tag.RemoveButton>}
            </Tag>)}
          </TagGroup.List>
        </TagGroup>}
        {canEditTags && tags.length < 32 && <EntryTagField workspace={workspace} source={transport.browseChoices} tags={tags}
          value={tagDraft} onChange={setTagDraft} onAdd={tag => saveTags([...tags.map(({id}) => ({id})), tag], true)}
          busy={busy} portal={tagPortal ?? portal} t={t}/>}
        {canEditTags && tags.length === 32 && <small>{t.selectionLimit}</small>}
      </section>}
      {details.imageInsights?.analysis && <section className="fms-inspector-insights">
        <header><h3>{t.imageAnalysis}</h3>
          {details.imageInsights.analysis.sample && <Chip size="sm" variant="soft">{t.imageAnalysisSample}</Chip>}
        </header>
        {details.imageInsights.analysis.description && <p>{details.imageInsights.analysis.description}</p>}
        {!!details.imageInsights.analysis.tags.length && <>
          <h4>{t.imageAnalysisTags}</h4>
          <ul className="fms-inspector-tags">
            {details.imageInsights.analysis.tags.map((label, index) => <li key={`${label}-${index}`}><Chip size="sm" variant="soft" color="accent">{label}</Chip></li>)}
          </ul>
        </>}
      </section>}
      {details.imageInsights?.ocr && <section className="fms-inspector-insights">
        <h3>{t.imageOcrText}</h3>
        {details.imageInsights.ocr.text ? <p className="fms-inspector-ocr" dir="auto">{details.imageInsights.ocr.text}</p>
          : <p className="fms-muted">{t.imageOcrEmpty}</p>}
        {details.imageInsights.ocr.partial && <small>{t.imageOcrPartial}</small>}
      </section>}
    </>}
    {!details&&!error&&<Spinner aria-label={t.loading} size="sm"/>}
    {error&&<div role="alert" className="fms-error"><p>{error}</p><Button size="sm" variant="tertiary" onPress={()=>retry(n=>n+1)}>{t.retry}</Button></div>}
    {details&&!canWrite&&<dl className="fms-file-facts fms-inspector-values">
      {([['alt',details.metadata.alt],['caption',details.metadata.caption]] as const).map(([key,value])=>value&&<div key={key}><dt>{t[key]}</dt><dd>{value}</dd></div>)}
    </dl>}
    {details&&canWrite&&<form className="fms-inspector-form" onSubmit={event=>{
      event.preventDefault();
      if (busy || !canWrite) return;
      const metadata = entry.mediaType === 'image' ? {alt, caption} : {caption};
      void mutate(async signal => {
        setSaved(false);
        const value = await transport.update(workspace, entry.id, {revision: details.revision, metadata}, signal);
        if (signal.aborted) return;
        setSaved(true);
        onChanged(value);
      });
    }}>
      {entry.mediaType==='image'&&<TextField value={alt} onChange={setAlt}><Label>{t.alt}</Label><TextArea variant="secondary" rows={2} maxLength={500}/></TextField>}
      <TextField value={caption} onChange={setCaption}><Label>{t.caption}</Label><TextArea variant="secondary" rows={2} maxLength={2000}/></TextField>
      <Button type="submit" size="sm" isDisabled={busy}>{busy?<Spinner size="sm"/>:t.save}</Button>
      {saved&&<p role="status" className="fms-muted">{t.saved}</p>}
    </form>}
    </div>
    {showComments&&tab==='comments'&&transport.comments&&<FileComments workspace={workspace} entry={entry.id} transport={transport.comments} t={t} portal={portal} controls={commentControls}/>}
  </section>;
}

function aspectRatio(width: number, height: number): string {
  let a = Math.round(width);
  let b = Math.round(height);
  while (b) [a, b] = [b, a % b];
  return a > 0 ? `${Math.round(width) / a}:${Math.round(height) / a}` : '—';
}
