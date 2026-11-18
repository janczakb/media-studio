import {Button} from '@heroui/react';
import {useState} from 'react';
import {ext,send} from '../core/webextension';import {permissionPattern,serverUrl} from '../core/urls';
import type {Snapshot} from '../core/types';import {Field,words} from './shared';
export function Connect({snapshot,onError}:{snapshot:Snapshot;onError:(v:string)=>void}){const t=words(snapshot.preferences.locale),[address,setAddress]=useState('https://flex-media-studio.test'),[busy,setBusy]=useState(false);
 const connect=async()=>{setBusy(true);onError('');try{const u=serverUrl(address);if(!await ext.permissions.request({origins:[permissionPattern(u.href)]}))throw new Error(t.permission);await send('pair',{apiBase:u.href.replace(/\/$/,'')});}catch(e){onError(e instanceof Error?e.message:'Connection failed.');}finally{setBusy(false);}};
 return <section className="fc-connect"><h2>{t.connect}</h2><p>{t.connectHint}</p>{snapshot.pairing?<><strong className="fc-pair-code">{snapshot.pairing.code}</strong><p>{t.code}</p><p role="status">{t.waiting}</p><Button size="sm" variant="secondary" onPress={()=>void send('pair.cancel')}>{t.cancel}</Button></>:<><Field label={t.address} type="url" value={address} onChange={setAddress}/><Button isPending={busy} isDisabled={busy} onPress={()=>void connect()}>{t.connect}</Button></>}</section>;
}
