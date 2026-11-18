import {Button,Input,Modal,Spinner} from '@heroui/react';
import {useEffect,useRef,useState} from 'react';
import type {Entry} from '../../contracts';
import type {Messages} from '../../ui';
import {fileNameParts} from '../../file-caption';
import {errorText} from '../../ui';

export function PreviewFileName({entry,editable,t,onRename}:{entry:Entry;editable:boolean;t:Messages;onRename:(name:string,signal:AbortSignal)=>Promise<void>}){
 const [editing,setEditing]=useState(false),[draft,setDraft]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const input=useRef<HTMLInputElement>(null),request=useRef<AbortController|null>(null),lock=useRef(false),skipBlur=useRef(false);
 useEffect(()=>()=>request.current?.abort(),[]);
 useEffect(()=>{if(editing){input.current?.focus();input.current?.select();}},[editing]);
 const parts=fileNameParts(entry);
 const title=<><span className="fms-preview-basename">{parts.title}</span><span className="fms-preview-extension">{parts.suffix}</span></>;
 if(!editable&&!editing)return <div className="fms-preview-file-title"><h2 title={entry.name}>{title}</h2></div>;
 const save=async()=>{if(skipBlur.current){skipBlur.current=false;return;}if(lock.current)return;const name=draft.trim();if(!name){input.current?.focus();return;}const full=name.toLowerCase().endsWith(parts.suffix.toLowerCase())?name:name+parts.suffix;if(full===entry.name){setEditing(false);return;}lock.current=true;setBusy(true);setError('');const controller=new AbortController();request.current=controller;try{await onRename(full,controller.signal);if(!controller.signal.aborted)setEditing(false);}catch(reason){if(!controller.signal.aborted){setError(errorText(reason));input.current?.focus();}}finally{lock.current=false;if(!controller.signal.aborted)setBusy(false);}};
 return <div className="fms-preview-file-title">
  {editing?<form onSubmit={event=>{event.preventDefault();void save();}} className="fms-preview-rename"><Input ref={input} variant="secondary" aria-label={t.renameFile} value={draft} maxLength={255} disabled={busy} onChange={event=>setDraft(event.target.value)} onBlur={()=>void save()} onKeyDown={event=>{event.stopPropagation();if(event.key==='Escape'){event.preventDefault();skipBlur.current=true;setEditing(false);setError('');}}}/><span className="fms-preview-extension">{parts.suffix}</span>{busy&&<Spinner size="sm"/>}</form>:<Modal.Heading title={entry.name}>{editable?<Button variant="ghost" className="fms-preview-rename-trigger" aria-label={t.renameFile} onPress={()=>{setDraft(parts.title);setError('');skipBlur.current=false;setEditing(true);}}>{title}</Button>:title}</Modal.Heading>}
  {error&&<p className="fms-preview-rename-error" role="alert">{error}</p>}
 </div>;
}
