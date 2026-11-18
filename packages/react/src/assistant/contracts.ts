export interface AssistantAction {ok:boolean;action:string;actionId:string;undoable:boolean;command?:'open'|'download';entry?:{id:string;name:string;kind:'file'|'folder'|'note';revision:number}}
export interface AssistantModel {id:string;label:string;usageWeight:number}
export interface AssistantConfiguration {enabled:boolean;models:AssistantModel[]}
export interface AssistantConversation {id:string;title:string;updated_at?:string;revision?:number}
export interface AssistantRun {id:string;state:'queued'|'running'|'completed'|'cancelled'|'failed';usage:Record<string,number>|null;error:string|null;model_key:string;actions?:AssistantAction[]}
export interface AssistantMessage {id:string;role:'user'|'assistant';content:string;run_id:string;attachments?:AssistantAttachment[]}
export interface AssistantThread extends AssistantConversation {messages:AssistantMessage[];nextCursor?:string|null;newerCursor?:string|null;run:AssistantRun|null}
export interface AssistantEvent {id:number;type:string;data:{text?:string;state?:string;error?:string|null;actionId?:string;ok?:boolean;action?:string;undoable?:boolean;command?:'open'|'download';entry?:AssistantAction['entry']}}
export interface AssistantProfile {revision:number;name:string;character:import('../characters/model').CharacterConfig|null}
export interface AssistantAttachment {id:string;name:string;size:number;mimeType:string}
export interface AssistantTransport {
  undo?(workspace:string,action:string,signal:AbortSignal):Promise<{ok:boolean}>;
  discardAttachment?(workspace:string,id:string,signal:AbortSignal):Promise<{ok:boolean}>;
  upload?(workspace:string,file:File,signal:AbortSignal):Promise<AssistantAttachment>;
  rename?(workspace:string,id:string,title:string,revision:number,signal:AbortSignal):Promise<AssistantConversation>;
  remove?(workspace:string,id:string,revision:number,signal:AbortSignal):Promise<AssistantConversation>;
  restore?(workspace:string,id:string,revision:number,signal:AbortSignal):Promise<AssistantConversation>;
  profile?(workspace:string,signal:AbortSignal):Promise<AssistantProfile>;
  saveProfile?(workspace:string,profile:AssistantProfile,signal:AbortSignal):Promise<AssistantProfile>;
  list(workspace:string,cursor:string|null,signal:AbortSignal,search?:string):Promise<{items:AssistantConversation[];nextCursor:string|null}>;
  create(workspace:string,signal:AbortSignal):Promise<AssistantConversation>;
  show(workspace:string,conversation:string,signal:AbortSignal,page?:{before?:string;after?:string}):Promise<AssistantThread>;
  send(workspace:string,conversation:string,input:{key:string;text:string;model:string;entries:string[];attachments?:string[]},signal:AbortSignal):Promise<{id:string}>;
  cancel(workspace:string,conversation:string,signal:AbortSignal):Promise<{ok:boolean}>;
  events(workspace:string,conversation:string,run:string,after:number,onEvent:(event:AssistantEvent)=>void,signal:AbortSignal):Promise<void>;
}
