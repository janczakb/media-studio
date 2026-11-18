import {Button,ComboBox,EmptyState,Input,Label,ListBox,Spinner} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {Add01Icon,Cancel01Icon} from '@hugeicons/core-free-icons';
import {useEffect,useState} from 'react';
import {send} from '../core/webextension';
import {words} from './shared';
export interface CaptureTag {id?:string;name?:string;label:string;}
interface Choice {id:string;label:string;}
const create='__capture-create-tag__';
/** Same ComboBox interaction as the file inspector: bounded async suggestions. */
export function CaptureTagField({connectionId,locale,value,onChange,disabled,portal,inPage=true}:{connectionId:string;locale:string;value:CaptureTag[];onChange:(tags:CaptureTag[])=>void;disabled:boolean;portal:HTMLElement;inPage?:boolean}){
 const t=words(locale),[query,setQuery]=useState('');
 const [result,setResult]=useState<{connection:string;query:string;items:Choice[];failed?:boolean}|null>(null);
 const name=query.trim(),normalized=name.toLocaleLowerCase();
 const current=result?.connection===connectionId&&result.query===normalized?result:null;
 const loading=!!normalized&&!current;
 const suggestions=current?.items.filter(item=>!value.some(tag=>tag.id===item.id||tag.label.toLocaleLowerCase()===item.label.toLocaleLowerCase()))??[];
 const exact=suggestions.find(item=>item.label.toLocaleLowerCase()===normalized);
 const canCreate=!!name&&!loading&&!current?.failed&&!exact&&!value.some(tag=>tag.label.toLocaleLowerCase()===normalized)&&value.length<32;
 const options=canCreate?[...suggestions,{id:create,label:name}]:suggestions;
 useEffect(()=>{
  if(!normalized)return;let alive=true;const timer=setTimeout(()=>{void send<{items:Choice[]}>(inPage?'drag.tags':'tags',{connectionId,search:name.slice(0,100)}).then(page=>{if(alive)setResult({connection:connectionId,query:normalized,items:page.items.slice(0,15)});},()=>{if(alive)setResult({connection:connectionId,query:normalized,items:[],failed:true});});},160);
  return()=>{alive=false;clearTimeout(timer);};
 },[connectionId,normalized,inPage]);
 const add=(key:string)=>{if(disabled||loading||value.length>=32)return;const item=suggestions.find(item=>item.id===key);if(item)onChange([...value,{id:item.id,label:item.label}]);else if(key===create&&canCreate)onChange([...value,{name,label:name}]);else return;setQuery('');};
 return <div className="fc-capture-tags"><Label>{t.tagLabel}</Label>
  {value.length>0&&<div className="fc-capture-tag-pills">{value.map((tag,index)=><span key={tag.id??tag.label}>{tag.label}<Button isIconOnly size="sm" variant="ghost" isDisabled={disabled} aria-label={t.removeTag.replace('{tag}',tag.label)} onPress={()=>onChange(value.filter((_,i)=>i!==index))}><HugeiconsIcon icon={Cancel01Icon} size={12}/></Button></span>)}</div>}
  <div className="fc-capture-tag-input" onKeyDownCapture={event=>{if(event.key==='Enter'&&!event.nativeEvent.isComposing&&event.target instanceof HTMLInputElement&&!event.target.getAttribute('aria-activedescendant')){event.preventDefault();event.stopPropagation();if(exact)add(exact.id);else if(canCreate)add(create);}}}>
   <ComboBox aria-label={t.addTags} variant="secondary" menuTrigger="input" allowsCustomValue allowsEmptyCollection inputValue={query} onInputChange={setQuery} selectedKey={null} items={options} onSelectionChange={key=>{if(key!==null)add(String(key));}} isDisabled={disabled||value.length>=32}>
    <Input variant="secondary" placeholder={t.addTags} maxLength={100}/>
    <ComboBox.Popover placement="top start" offset={5} UNSTABLE_portalContainer={portal} className="fc-capture-tag-popover">
     <ListBox<Choice> renderEmptyState={()=><EmptyState>{loading?<Spinner size="sm" aria-label={t.loading}/>:current?.failed?t.tagSuggestionsUnavailable:t.noTags}</EmptyState>}>
      {item=><ListBox.Item id={item.id} textValue={item.label}>{item.id===create?<><HugeiconsIcon icon={Add01Icon} size={15}/>{t.createTag.replace('{tag}',item.label)}</>:item.label}</ListBox.Item>}
     </ListBox>
    </ComboBox.Popover>
   </ComboBox>
   <Button isIconOnly size="sm" variant="secondary" aria-label={t.addTags} isDisabled={disabled||loading||(!exact&&!canCreate)} onPress={()=>add(exact?.id??create)}><HugeiconsIcon icon={Add01Icon} size={16}/></Button>
  </div>
 </div>;
}
