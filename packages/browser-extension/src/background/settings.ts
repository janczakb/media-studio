import {ext} from '../core/webextension';
import {defaults,type Preferences,type Connection,type Pairing} from '../core/types';
export async function preferences():Promise<Preferences>{const v=(await ext.storage.local.get('preferences')).preferences;return {...defaults,...(typeof v==='object'&&v?v:{})} as Preferences;}
export async function connections():Promise<Connection[]>{const v=(await ext.storage.local.get('connections')).connections;return Array.isArray(v)?v as Connection[]:[];}
export async function pairing():Promise<Pairing|null>{const v=(await ext.storage.local.get('pairing')).pairing;return v&&typeof v==='object'?v as Pairing:null;}
export async function connection(id:string):Promise<Connection>{const value=(await connections()).find(c=>c.id===id);if(!value)throw new Error('Connect this workspace again.');if(Date.parse(value.expiresAt)<=Date.now())throw new Error('This connection expired. Connect again.');return value;}
