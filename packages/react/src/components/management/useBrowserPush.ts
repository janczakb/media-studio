import {useCallback, useEffect, useRef, useState} from 'react';
import {activatePushWorker, createPushSubscription, pushDeviceId, pushKeyMatches, type BrowserPushSettings, type BrowserPushTransport, type PushEvent} from '../../browser-push';
import {errorText, type Messages} from '../../ui';

function pushError(reason: unknown, t: Messages): string {
  if (reason instanceof DOMException) {
    return reason.name === 'NotAllowedError' ? t.pushDenied : t.pushFailed;
  }
  if (reason instanceof Error && reason.message === 'push-subscription-timeout') return t.pushTimeout;
  return errorText(reason);
}

export function useBrowserPush(transport: BrowserPushTransport | undefined, workspace: string | undefined, visible: boolean, t: Messages, locale:string) {
  const [settings, setSettings] = useState<BrowserPushSettings | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [permission, setPermission] = useState<NotificationPermission>('default');
  const [subscribed, setSubscribed] = useState(false);
  const operation = useRef<AbortController | null>(null);
  const generation = useRef(0);
  const supported = typeof window !== 'undefined' && window.isSecureContext
    && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
  const refresh = useCallback(() => setAttempt(value => value + 1), []);

  useEffect(() => {
    setSettings(null);
    setBusy(false);
    setError('');
    setSubscribed(false);
    return () => {operation.current?.abort(); operation.current = null;};
  }, [transport, workspace]);

  useEffect(() => {
    if (!visible || !transport || !workspace || operation.current) return;
    const currentGeneration = generation.current;
    const controller = new AbortController();
    const load = async () => {
      const workerUrl = new URL(transport.workerUrl, window.location.href);
      const scope = new URL('./', workerUrl).href;
      const registration = supported ? await navigator.serviceWorker.getRegistration(scope) : undefined;
      const subscription = registration?.active?.scriptURL === workerUrl.href
        ? await registration.pushManager.getSubscription() : null;
      const device = subscription ? await pushDeviceId(subscription) : null;
      const value = await transport.status(workspace, device, controller.signal);
      if (controller.signal.aborted || currentGeneration !== generation.current || operation.current) return;
      setSettings(value);
      setPermission(supported ? Notification.permission : 'default');
      setSubscribed(subscription !== null && value.subscribed && Notification.permission === 'granted'
        && (!value.publicKey || pushKeyMatches(subscription, value.publicKey)));
    };
    setSettings(null);
    setError('');
    void load().catch(reason => {if (!controller.signal.aborted) setError(pushError(reason, t));});
    return () => controller.abort();
  }, [visible, transport, workspace, supported, attempt]);

  useEffect(() => {
    if (!visible || !supported) return;
    const changed = () => {if (document.visibilityState === 'visible') refresh();};
    const crossTab = (event: StorageEvent) => {if (event.key === 'fms.push.changed') refresh();};
    document.addEventListener('visibilitychange', changed);
    window.addEventListener('storage', crossTab);
    return () => {
      document.removeEventListener('visibilitychange', changed);
      window.removeEventListener('storage', crossTab);
    };
  }, [visible, supported, refresh]);

  const announceChange = () => {
    try {localStorage.setItem('fms.push.changed', crypto.randomUUID());} catch {}
  };

  const toggle = async (enabled: boolean) => {
    if (!transport || !workspace || !settings || !supported || operation.current || (enabled && !settings.available)) return;
    const controller = new AbortController();
    operation.current = controller;
    generation.current++;
    setBusy(true);
    setError('');
    let created: PushSubscription | null = null;
    try {
      if (enabled) {
        const granted = await Notification.requestPermission();
        setPermission(granted);
        if (granted !== 'granted') throw new Error(t.pushDenied);
        if (controller.signal.aborted) return;
        if (!settings.publicKey) throw new Error(t.pushUnavailable);
        const workerUrl = new URL(transport.workerUrl, window.location.href);
        if (workerUrl.origin !== window.location.origin) throw new Error(t.pushSameOrigin);
        const registration = await navigator.serviceWorker.register(workerUrl.href, {updateViaCache: 'none'});
        await activatePushWorker(registration, controller.signal);
        let existing = await registration.pushManager.getSubscription();
        if (existing && !pushKeyMatches(existing, settings.publicKey)) {
          await transport.unsubscribe(workspace, await pushDeviceId(existing), controller.signal);
          await existing.unsubscribe();
          existing = null;
        }
        const subscription = existing ?? await createPushSubscription(registration, settings.publicKey, controller.signal);
        if (!existing) created = subscription;
        if (controller.signal.aborted) {await created?.unsubscribe(); return;}
        await transport.subscribe(workspace, subscription.toJSON(), window.location.pathname, locale, controller.signal);
        if (!controller.signal.aborted) setSubscribed(true);
      } else {
        const workerUrl = new URL(transport.workerUrl, window.location.href);
        const registration = await navigator.serviceWorker.getRegistration(new URL('./', workerUrl).href);
        const subscription = registration?.active?.scriptURL === workerUrl.href
          ? await registration.pushManager.getSubscription() : null;
        if (subscription) {
          await transport.unsubscribe(workspace, await pushDeviceId(subscription), controller.signal);
          await subscription.unsubscribe();
        }
        if (!controller.signal.aborted) setSubscribed(false);
      }
      announceChange();
    } catch (reason) {
      await created?.unsubscribe().catch(() => {});
      if (!controller.signal.aborted) setError(pushError(reason, t));
    } finally {
      if (operation.current === controller) {
        operation.current = null;
        generation.current++;
        setBusy(false);
      }
    }
  };

  const changeEvents = async (events: PushEvent[]) => {
    if (!transport || !workspace || !settings?.available || operation.current) return;
    const controller = new AbortController();
    operation.current = controller;
    generation.current++;
    setBusy(true);
    setError('');
    try {
      const result = await transport.preferences(workspace, events, controller.signal);
      if (!controller.signal.aborted) setSettings(previous => previous && {...previous, events: result.events});
      announceChange();
    } catch (reason) {
      if (!controller.signal.aborted) setError(pushError(reason, t));
    } finally {
      if (operation.current === controller) {operation.current = null; generation.current++; setBusy(false);}
    }
  };

  const hint = !supported ? t.pushUnsupported : permission === 'denied' ? t.pushDenied
    : settings && !settings.available ? t.pushUnavailable : t.pushHint;
  return {settings, busy, error, supported, subscribed, hint, toggle, changeEvents, refresh};
}
export type BrowserPushControl = ReturnType<typeof useBrowserPush>;
