import {BrowserPushSwitch} from './BrowserPushSwitch';
import type {BrowserPushControl} from './useBrowserPush';
import type {Messages} from '../../ui';
import {Avatar,Button} from '@heroui/react';
import {AtIcon,Notification01Icon} from '@hugeicons/core-free-icons';
import {useEffect,useRef,useState} from 'react';
import type {CursorPage,ManagementTransport,StudioNotification} from '../../management-contracts';
import {Icon,errorText} from '../../ui';
import {useDateText} from '../layout/TimePreferences';
import {CursorFooter,ListState,ManagementTabs,PageFrame,VirtualRows,useManagementMessages,useCursorPage,type ManagementLocale} from './shared';

export function notificationEventLabels(t:import('./shared').ManagementMessages):Record<string,string>{return {mentioned:t.eventMentioned,uploaded:t.eventCreated,guest_uploaded:t.eventGuestUploaded,trashed:t.eventTrashed,restored:t.eventRestored,processed:t.eventReady,failed:t.eventFailed,shared:t.eventShared,revoked:t.eventRevoked,purged:t.eventPurged};}
export function relativeTime(value:string,locale:string,now:number):string {const seconds=Math.max(0,(now-Date.parse(value))/1000),format=new Intl.RelativeTimeFormat(locale,{numeric:'auto'});if(!Number.isFinite(seconds))return '—';for(const [unit,size] of [['year',31536000],['month',2592000],['day',86400],['hour',3600],['minute',60]] as const)if(seconds>=size)return format.format(-Math.floor(seconds/size),unit);return format.format(-Math.floor(seconds),'second');}
export function NotificationsPage({browserPush,t:appMessages,workspace,transport,locale='en',onOpen,onUnread,canMarkRead=true}:{browserPush:BrowserPushControl;t:Messages;workspace:string;transport:ManagementTransport;locale?:ManagementLocale;onOpen?:(id:string)=>void;onUnread?:(count:number)=>void;canMarkRead?:boolean}){
 const t=useManagementMessages(),date=useDateText(locale),[filter,setFilter]=useState('all'),[seen,setSeen]=useState(new Set<string>()),[error,setError]=useState(''),[now,setNow]=useState(Date.now());
 const inFlight=useRef(new Set<string>()),requests=useRef(new Set<AbortController>()),callback=useRef(onUnread);callback.current=onUnread;
 useEffect(()=>{setSeen(new Set());inFlight.current.clear();return()=>{for(const c of requests.current)c.abort();requests.current.clear();};},[workspace]);
 useEffect(()=>{const timer=setInterval(()=>{if(!document.hidden)setNow(Date.now());},60000);return()=>clearInterval(timer);},[]);
 const identity=JSON.stringify([workspace,filter]);
 const page=useCursorPage<StudioNotification,CursorPage<StudioNotification>>(transport.notifications?(cursor,signal)=>transport.notifications!(workspace,filter==='unread',cursor,signal):undefined,identity);
 const labels=notificationEventLabels(t);
 const markVisible=(items:StudioNotification[])=>{const ids=items.filter(i=>!i.readAt&&!seen.has(i.id)&&!inFlight.current.has(i.id)).map(i=>i.id);if(!canMarkRead||!ids.length||!transport.readVisibleNotifications&&!transport.readNotification)return;
  ids.forEach(id=>inFlight.current.add(id));const controller=new AbortController();requests.current.add(controller);
  const write=transport.readVisibleNotifications?transport.readVisibleNotifications(workspace,ids,controller.signal):Promise.all(ids.map(id=>transport.readNotification!(workspace,id,true,controller.signal))).then(()=>({updated:ids.length,unreadCount:-1}));
  void write.then(result=>{if(controller.signal.aborted)return;setSeen(previous=>new Set([...previous,...ids].slice(-500)));if(result.unreadCount>=0)callback.current?.(result.unreadCount);}).catch(e=>{if(!controller.signal.aborted)setError(errorText(e));}).finally(()=>{requests.current.delete(controller);ids.forEach(id=>inFlight.current.delete(id));});
 };
 return <div className="fms-notifications"><PageFrame title={t.notifications} hint="" actions={<BrowserPushSwitch push={browserPush} t={appMessages}/>}>
  <div className="fms-management-tabs"><ManagementTabs label={t.notifications} value={filter} options={[["all",t.all],["unread",t.unread]]} onChange={setFilter}/></div>
  {(error||browserPush.error)&&<p role="alert" className="fms-error">{error||browserPush.error}</p>}
  {!page.data?.items.length?<ListState kind="notifications" pending={page.pending} error={page.error} empty={t.notificationEmpty} available={page.available} retry={page.refresh} t={t}/>:<VirtualRows rowHeight={80} onVisible={markVisible} items={page.data.items} identity={identity+page.cursor} getKey={item=>item.id} render={item=>{
   const canOpen=!!onOpen&&!!item.entryId&&!['purged','trashed'].includes(item.event),unread=!item.readAt&&!seen.has(item.id);
   return <><div className="fms-notification-content" data-unread={unread||undefined}><Icon icon={item.event==='mentioned'?AtIcon:Notification01Icon} size={22} strokeWidth={1.5}/><div><Button variant="ghost" className="fms-notification-link" isDisabled={!canOpen} onPress={()=>{if(canOpen)onOpen!(item.entryId!);}}><span>{labels[item.event]??t.eventUpdated} <u>{item.name}</u></span></Button>{item.actor?.name&&<div className="fms-notification-by"><span>{locale==='pl'?'przez':'by'}</span><Avatar size="sm">{item.actor.avatarUrl&&<Avatar.Image src={item.actor.avatarUrl} alt=""/>}<Avatar.Fallback>{item.actor.name.slice(0,1)}</Avatar.Fallback></Avatar><span>{item.actor.name}</span></div>}</div></div><time dateTime={item.createdAt} title={date(item.createdAt)}>{relativeTime(item.createdAt,locale,now)}</time></>;
  }}/>}
  <CursorFooter pageNumber={page.pageNumber} previous={page.data?.previousCursor??null} next={page.data?.nextCursor??null} busy={page.pending} onPage={page.setCursor} refresh={page.refresh} t={t}/>
 </PageFrame></div>;
}
