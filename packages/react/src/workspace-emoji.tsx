import {useLayoutEffect,useRef,useState} from 'react';
import {createRoot} from 'react-dom/client';
import {InputGroup,Button,I18nProvider,isRTL} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {Search01Icon,Cancel01Icon} from '@hugeicons/core-free-icons';
import {Root,SearchInput,SkinTones,useSkinTone,Viewport,List,Empty,Loading,LoadError,CategoryNav,defaultPickerTokens,Categories,useSearchActions,useSearchState} from 'emoji-picker-react/primitives';
import {lazyEmojiData} from './emoji-data';
import type {WorkspaceMessages} from './workspace-i18n';

export interface EmojiToneControl {element:HTMLElement;value:string;onChange:(value:string)=>void}
function SkinToneSlot({onControl}:{onControl:(control:EmojiToneControl|null)=>void}) {
 const host=useRef<HTMLDivElement>(null),[tone,setTone]=useSkinTone();
 const callback=useRef(onControl),change=useRef(setTone);callback.current=onControl;change.current=setTone;
 useLayoutEffect(()=>{if(host.current)callback.current({element:host.current,value:tone,onChange:value=>{if(Object.values(SkinTones).includes(value as SkinTones))change.current(value as SkinTones);}});},[tone]);
 useLayoutEffect(()=>()=>callback.current(null),[]);
 return <div className="fms-emoji-tone-slot" ref={host}/>;
}

function PickerSearch({messages,onToneControl}:{messages:WorkspaceMessages;onToneControl:(control:EmojiToneControl|null)=>void}){
 const {search}=useSearchState(),{clear}=useSearchActions();
 return <div className="fms-emoji-search">
  <InputGroup variant="secondary" className="fms-emoji-search-group">
   <InputGroup.Prefix><HugeiconsIcon icon={Search01Icon} size={20} strokeWidth={1.75}/></InputGroup.Prefix>
   <SearchInput as={InputGroup.Input} className="fms-emoji-search-input"/>
   {search&&<InputGroup.Suffix><Button size="sm" isIconOnly variant="ghost" aria-label={messages.emojiClear} data-epr-part="search-clear" onPressStart={clear}><HugeiconsIcon icon={Cancel01Icon} size={16} strokeWidth={1.75}/></Button></InputGroup.Suffix>}
  </InputGroup>
  <SkinToneSlot onControl={onToneControl}/>
 </div>;
}
export function mountWorkspaceEmoji(element:HTMLElement,{locale,fallbackLocale,messages,onSelect,onToneControl}:{locale:string;fallbackLocale:string;messages:WorkspaceMessages;onSelect:(emoji:string)=>void;onToneControl:(control:EmojiToneControl|null)=>void}) {
 const root=createRoot(element);
 function Picker(){const [search,setSearch]=useState('');return <I18nProvider locale={locale}><Root searchValue={search} onSearchChange={setSearch} dir={isRTL(locale)?'rtl':'ltr'} className="fms-emoji-engine" appearance="default" emojiStyle="native" suggestedEmojisMode="recent" categories={[{category:Categories.SUGGESTED,name:messages.emojiRecent},Categories.SMILEYS_PEOPLE,Categories.ANIMALS_NATURE,Categories.FOOD_DRINK,Categories.TRAVEL_PLACES,Categories.ACTIVITIES,Categories.OBJECTS,Categories.SYMBOLS,Categories.FLAGS]} autoFocusSearch emojiData={lazyEmojiData(locale)} lang={locale} style={{...defaultPickerTokens,height:390,width:'100%',background:'var(--surface)',color:'var(--foreground)'}} onEmojiClick={data=>onSelect(data.emoji)} labels={{searchPlaceholder:messages.emojiSearch,searchLabel:messages.emojiSearch,searchClear:messages.emojiClear,categoryNavigation:messages.emojiCategories,searchResultsNone:messages.emojiEmpty,searchResultsOne:messages.emojiOneResult,searchResultsMany:messages.emojiManyResults,loading:messages.emojiLoading,loadingError:messages.emojiLoadError,retryLoading:messages.tryAgain,skinToneNeutral:messages.skinNeutral,skinToneLight:messages.skinLight,skinToneMediumLight:messages.skinMediumLight,skinToneMedium:messages.skinMedium,skinToneMediumDark:messages.skinMediumDark,skinToneDark:messages.skinDark}}>
  <PickerSearch messages={messages} onToneControl={onToneControl}/>
  <Viewport><List/><Empty/><Loading/><LoadError/></Viewport>
  <CategoryNav/>
 </Root></I18nProvider>;}
 root.render(<Picker/>);
 return {destroy(){queueMicrotask(()=>root.unmount());}};
}
