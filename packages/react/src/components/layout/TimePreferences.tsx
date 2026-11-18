import {defaultLocale} from '../../workspace-i18n';
import {createContext,useContext,useMemo,type ReactNode} from 'react';
export const browserTimeZone=()=>Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC';
export function validTimeZone(value:unknown):value is string {if(typeof value!=='string')return false;try{new Intl.DateTimeFormat('en',{timeZone:value}).format();return true;}catch{return false;}}
const TimeContext=createContext<{zone:string|undefined;locale:string|undefined}>({zone:undefined,locale:undefined});
export function TimePreferences({zone,locale,children}:{zone:string;locale:string;children:ReactNode}){const value=useMemo(()=>({zone,locale}),[zone,locale]);return <TimeContext value={value}>{children}</TimeContext>;}
export const useStudioTimeZone=()=>useContext(TimeContext).zone??browserTimeZone();
export const useStudioLocale=()=>useContext(TimeContext).locale??defaultLocale;
export function useDateText(locale?:string){const fallback=useStudioLocale(),language=locale??fallback,timeZone=useStudioTimeZone();return useMemo(()=>{const formatter=new Intl.DateTimeFormat(language,{dateStyle:'medium',timeStyle:'short',timeZone});return (value:string|null)=>value&&Number.isFinite(Date.parse(value))?formatter.format(new Date(value)):'—';},[language,timeZone]);}
