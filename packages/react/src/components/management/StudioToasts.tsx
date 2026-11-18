import {Toast, type ToastQueue} from '@heroui/react';
import {UNSAFE_PortalProvider} from 'react-aria/PortalProvider';
import {useEffect, useLayoutEffect, useRef, useState} from 'react';
import type {ManagementTransport} from '../../management-contracts';
import {notificationEventLabels} from './NotificationsPage';
import {useManagementMessages, type ManagementLocale} from './shared';

const INBOX_INTERVAL_MS = 15_000;

export function StudioToasts({queue, portal, label}: {queue: ToastQueue; portal: HTMLDivElement; label: string}) {
  // React Aria's outside-hiding walk can mark a sibling toast inert inside a
  // ShadowRoot. Keep the region inside the active dialog's focus/accessibility
  // scope rather than overriding inert or weakening modal isolation.
  const [container,setContainer]=useState<HTMLElement>(portal);
  useLayoutEffect(()=>{
    const update=()=>{const dialogs=portal.querySelectorAll<HTMLElement>('[data-slot="modal-dialog"]');setContainer(dialogs.item(dialogs.length-1)??portal);};
    const modalNode=(node:Node)=>node instanceof Element&&(node.matches('[data-slot="modal-dialog"],[data-slot="modal-backdrop"]')||node.querySelector('[data-slot="modal-dialog"]'));
    const observer=new MutationObserver(records=>{if(records.some(r=>[...r.addedNodes,...r.removedNodes].some(modalNode)))update();});
    observer.observe(portal,{childList:true,subtree:true});update();return()=>observer.disconnect();
  },[portal]);
  return <UNSAFE_PortalProvider getContainer={() => container}>
    <Toast.Provider queue={queue} placement="top end" className={'fms-toast-region'+(container!==portal?' is-in-modal':'')} aria-label={label}/>
  </UNSAFE_PortalProvider>;
}

export function useInboxToasts(
  list: ManagementTransport['notifications'],
  workspace: string | undefined,
  locale: ManagementLocale,
  queue: ToastQueue,
) {
  const managementMessages=useManagementMessages();
  const currentMessages=useRef(managementMessages);currentMessages.current=managementMessages;
  const [badge,setBadge]=useState<{workspace:string|undefined;count:number}>({workspace,count:0});
  const currentLocale = useRef(locale);
  currentLocale.current = locale;

  useEffect(() => {
    if (!list || !workspace) return;
    const controller = new AbortController();
    const fetchInbox = () => list(workspace, false, null, controller.signal);
    const toastKeys = new Set<string>();
    let previousPage: Set<string> | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let pending = false;

    async function checkInbox() {
      clearTimeout(timer);
      if (pending || document.hidden || controller.signal.aborted) return;
      pending = true;
      try {
        const page = await fetchInbox();const {items}=page;
        if(!controller.signal.aborted)setBadge({workspace,count:page.unreadCount??Math.min(10,items.filter(item=>item.readAt===null).length)});
        if (controller.signal.aborted) return;
        if (previousPage) {
          const previous = previousPage;
          const knownIndex = items.findIndex(item => previous.has(item.id));
          const arrivals = (knownIndex < 0 ? items : items.slice(0, knownIndex))
            .filter(item => item.readAt === null)
            .slice(0, 3);
          const locale = currentLocale.current;
          const labels = notificationEventLabels(currentMessages.current);
          for (const item of arrivals.reverse()) {
            const key = queue.add({
              title: labels[item.event] ?? currentMessages.current.eventUpdated,
              description: item.name,
              variant: item.event === 'failed' ? 'danger' : 'accent',
            }, {timeout: 6000, onClose: () => toastKeys.delete(key)});
            toastKeys.add(key);
          }
        }
        previousPage = new Set(items.map(item => item.id));
      } catch (error) {
        const status = (error as {status?: number} | null)?.status;
        if (status === 401 || status === 403 || status === 404) controller.abort();
      } finally {
        pending = false;
        if (!controller.signal.aborted && !document.hidden) timer = setTimeout(checkInbox, INBOX_INTERVAL_MS);
      }
    }

    document.addEventListener('visibilitychange', checkInbox);
    void checkInbox();
    return () => {
      controller.abort();
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', checkInbox);
      for (const key of toastKeys) queue.close(key);
    };
  }, [list, workspace, queue]);
  return {unread:badge.workspace===workspace?badge.count:0,setUnread:(count:number)=>setBadge({workspace,count})};
}
