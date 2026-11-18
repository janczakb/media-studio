import {Autocomplete,EmptyState,Header,Label,ListBox,ListLayout,SearchField,Virtualizer,useFilter} from '@heroui/react';
import {useMemo,useState} from 'react';

export function TimeZoneSelect({value,onChange,portal,pl}:{value:string;onChange:(zone:string)=>void;portal:HTMLElement;pl:boolean}){
 const [query,setQuery]=useState('');const {contains}=useFilter({sensitivity:'base'});
 const zones=useMemo(()=>[...new Set(['UTC',...Intl.supportedValuesOf('timeZone'),value])].sort(),[value]);
 const sections=useMemo(()=>{const groups=new Map<string,{id:string;name:string}[]>();for(const id of zones){const name=id.replaceAll('_',' ').replaceAll('/',' / ');if(query&&!contains(name,query)&&!contains(id,query))continue;const group=id.includes('/')?id.split('/')[0]!:'UTC';const items=groups.get(group)??[];items.push({id,name});groups.set(group,items);}return [...groups].map(([id,items])=>({id,name:id,items}));},[zones,query,contains]);
 return <Autocomplete className="fms-timezone-select" variant="secondary" aria-label={pl?'Ustaw strefę czasową':'Set timezone'} placeholder={pl?'Wybierz strefę':'Select timezone'} selectionMode="single" value={value} onChange={key=>{if(typeof key==='string'&&zones.includes(key))onChange(key);}}>
  <Label>{pl?'Ustaw strefę czasową':'Set timezone'}</Label><Autocomplete.Trigger><Autocomplete.Value/><Autocomplete.Indicator/></Autocomplete.Trigger>
  <Autocomplete.Popover UNSTABLE_portalContainer={portal} className="fms-timezone-popover"><Autocomplete.Filter inputValue={query} onInputChange={setQuery}><SearchField autoFocus aria-label={pl?'Szukaj strefy czasowej':'Search timezones'} variant="secondary"><SearchField.Group><SearchField.SearchIcon/><SearchField.Input placeholder={pl?'Szukaj strefy…':'Search timezones…'}/><SearchField.ClearButton/></SearchField.Group></SearchField>
   <Virtualizer layout={ListLayout} layoutOptions={{rowHeight:40,headingHeight:28}}><ListBox items={sections} renderEmptyState={()=><EmptyState>{pl?'Brak wyników':'No results found'}</EmptyState>}>{section=><ListBox.Section id={section.id}><Header>{section.name}</Header>{section.items.map(zone=><ListBox.Item key={zone.id} id={zone.id} textValue={zone.name}><Label>{zone.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox.Section>}</ListBox></Virtualizer>
  </Autocomplete.Filter></Autocomplete.Popover>
 </Autocomplete>;
}
