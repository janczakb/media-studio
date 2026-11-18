import {Button, SearchField} from '@heroui/react';
import {Search01Icon, Tag01Icon} from '@hugeicons/core-free-icons';
import {useEffect, useState} from 'react';
import type {CursorPage, ManagementTransport, StudioTag} from '../../management-contracts';
import {Icon} from '../../ui';
import {CursorFooter, ListState, PageFrame, VirtualRows, useManagementMessages, useCursorPage, type ManagementLocale} from './shared';

export function TagsPage({workspace,transport,locale='en',onOpenTag}:{workspace:string;transport:ManagementTransport;locale?:ManagementLocale;onOpenTag:(tag:StudioTag)=>void}) {
  const t=useManagementMessages();
  const [search,setSearch]=useState('');
  const [settled,setSettled]=useState('');
  useEffect(()=>{
    const timer=setTimeout(()=>setSettled(search.trim()),250);
    return ()=>clearTimeout(timer);
  },[search]);
  const identity=JSON.stringify([workspace,settled]);
  const page=useCursorPage<StudioTag,CursorPage<StudioTag>>(transport.tags?(cursor,signal)=>transport.tags!(workspace,settled,cursor,signal):undefined,identity);
  const tags=Array.from(new Map(page.data?.items.map(tag=>[tag.id,tag])).values());
  const pending=page.pending||search.trim()!==settled;
  return <PageFrame title={t.tags} hint={t.tagsHint}>
    <div className="fms-management-filters"><SearchField className="fms-management-search" aria-label={t.searchTags} value={search} onChange={setSearch}>
      <SearchField.Group><SearchField.SearchIcon><Icon icon={Search01Icon} size={20}/></SearchField.SearchIcon><SearchField.Input placeholder={t.searchTags} maxLength={100}/><SearchField.ClearButton aria-label={t.clearSearch}/></SearchField.Group>
    </SearchField></div>
    <div className="fms-management-columns"><span>{t.name}</span><span/><span/><span/></div>
    {pending||!tags.length?<ListState kind="tags" pending={pending} error={page.error} empty={t.tagEmpty} available={page.available} retry={page.refresh} t={t}/>:<VirtualRows items={tags} identity={identity+page.cursor} getKey={tag=>tag.id} render={tag=><>
      <div className="fms-management-identity"><Icon icon={Tag01Icon}/><div><strong>{tag.label}</strong>{(tag.locale||tag.type)&&<small>{[tag.locale,tag.type].filter(Boolean).join(' · ')}</small>}</div></div><span/><span/>
      <div><Button size="sm" variant="tertiary" aria-label={`${t.openTag}: ${tag.label}`} onPress={()=>onOpenTag(tag)}>{t.openTag}</Button></div>
    </>}/>}
    <CursorFooter pageNumber={page.pageNumber} previous={page.data?.previousCursor??null} next={page.data?.nextCursor??null} busy={pending} onPage={page.setCursor} refresh={page.refresh} t={t}/>
  </PageFrame>;
}
