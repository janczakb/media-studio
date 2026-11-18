import {defaultShortcuts,type Shortcuts} from './shortcuts';
export type AssetKind='image'|'video'|'audio'|'document';
export interface Asset {id:string;url:string;previewUrl:string|null;name:string;kind:AssetKind;width:number;height:number;pageUrl:string;description:string;inline?:string;localBlobId?:string;tags?:string[];fallbackUrl?:string;}
export interface CaptureBatch {id:string;title:string;url:string;assets:Asset[];truncated:boolean;createdAt:number;}
export interface Connection {id:string;token:string;apiBase:string;workspace:{id:string;name:string};device:string;expiresAt:string;}
export interface Preferences {locale:'en'|'pl';theme:'light'|'dark'|'system';highResolution:boolean;askDestination:boolean;quickMode:'off'|'dock'|'popup';altRightClick:boolean;retina:boolean;screenshotFormat:'png'|'jpeg';scrollDelay:number;excludedUrls:string[];excludedHosts:string[];maxDownloadBytes:number;concurrency:number;shortcuts:Shortcuts;browserSession:boolean;}
export const defaults:Preferences={locale:'en',theme:'system',highResolution:true,askDestination:true,quickMode:'off',altRightClick:true,retina:true,screenshotFormat:'png',scrollDelay:500,excludedUrls:[],excludedHosts:[],maxDownloadBytes:64*1024*1024,concurrency:2,shortcuts:defaultShortcuts,browserSession:false};
export type JobState='queued'|'downloading'|'uploading'|'paused'|'error'|'done'|'cancelled';
export interface CaptureTagInput {id?:string;name?:string;}
export interface Job {id:string;connectionId:string;parent:string|null;asset:Asset;state:JobState;loaded:number;size:number;attempts:number;error:string|null;session:string|null;entry:string|null;updatedAt:number;createdAt:number;nextAttemptAt:number;tags:string[];tagRefs?:CaptureTagInput[];description:string;}
export interface StoredBlob {id:string;blob:Blob;}
export interface Pairing {id:string;code:string;verificationUrl:string;verifier:string;apiBase:string;expiresAt:number;nextPollAt:number;}
export interface Snapshot {connections:Omit<Connection,'token'>[];preferences:Preferences;jobs:Job[];pairing:Omit<Pairing,'verifier'>|null;}
export interface UploadPolicy {enabled:boolean;chunkBytes:number;maxBytes:number;ordinaryMaxBytes:number;}
export interface UploadSession {id:string;state:string;chunkBytes:number;size:number;acceptedParts:number[];entry:{id:string}|null;}
