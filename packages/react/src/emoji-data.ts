import type {EmojiData,EmojiDataLoader} from 'emoji-picker-react/primitives';
import {emojiLocales} from './emoji-locales';
import {defaultLocale,localeCandidates} from './workspace-i18n';
const retries=new Map<string,number>();
const pending=new Map<string,Promise<EmojiData>>();
const available=new Set<string>(emojiLocales);
export function emojiDataLocale(locale:string):string {
 for(const value of [locale,defaultLocale]){
  for(const candidate of localeCandidates(value)){
   // The package names the traditional Chinese dataset zh-hant.
   const key=candidate==='zh-tw'||candidate==='zh-hk'?'zh-hant':candidate;
   if(available.has(key))return key;
  }
 }
 throw new Error('No emoji fallback dataset is available.');
}
export function lazyEmojiData(locale:string):EmojiDataLoader {
 const language=emojiDataLocale(locale);
 return async({signal})=>{
  let data=pending.get(language);
  if(!data){data=import(new URL(`./emoji-data-${language}.js${retries.has(language)?'?retry='+retries.get(language):''}`,import.meta.url).href).then(module=>module.default as EmojiData).catch(error=>{pending.delete(language);retries.set(language,(retries.get(language)??0)+1);throw error;});pending.set(language,data);}
  let result:EmojiData;try{result=await data;}catch(error){if(language===defaultLocale)throw error;return lazyEmojiData(defaultLocale)({...(signal?{signal}:{})});}
  if(signal?.aborted)throw new DOMException('Aborted','AbortError');
  return result;
 };
}
