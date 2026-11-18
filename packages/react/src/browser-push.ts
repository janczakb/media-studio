export const pushEvents = ['mentioned', 'uploaded', 'guest_uploaded', 'processed', 'trashed', 'restored', 'purged'] as const;
export type PushEvent = typeof pushEvents[number];
export interface BrowserPushSettings {
  available: boolean;
  publicKey: string | null;
  subscribed: boolean;
  events: PushEvent[];
}
export interface BrowserPushTransport {
  workerUrl: string;
  status(workspace: string, device: string | null, signal: AbortSignal): Promise<BrowserPushSettings>;
  preferences(workspace: string, events: PushEvent[], signal: AbortSignal): Promise<{events: PushEvent[]}>;
  subscribe(workspace: string, subscription: PushSubscriptionJSON, path: string, locale:string, signal: AbortSignal): Promise<{subscribed: boolean}>;
  unsubscribe(workspace: string, device: string, signal: AbortSignal): Promise<{subscribed: boolean}>;
}

export async function pushDeviceId(subscription: PushSubscription): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(subscription.endpoint));
  return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
}

export function pushPublicKey(value: string): Uint8Array<ArrayBuffer> {
  const bytes = atob(value.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(bytes, character => character.charCodeAt(0));
}

export async function activatePushWorker(registration: ServiceWorkerRegistration, signal: AbortSignal): Promise<void> {
  if (registration.active) return;
  const worker = registration.installing ?? registration.waiting;
  if (!worker) throw new Error('The notification service could not start.');
  await new Promise<void>((resolve, reject) => {
    const done = (error?: Error) => {
      worker.removeEventListener('statechange', changed);
      signal.removeEventListener('abort', aborted);
      clearTimeout(timeout);
      if (error) reject(error);
      else resolve();
    };
    const changed = () => {
      if (worker.state === 'activated') done();
      else if (worker.state === 'redundant') done(new Error('The notification service could not start.'));
    };
    const aborted = () => done(new DOMException('Aborted', 'AbortError'));
    const timeout = setTimeout(() => done(new Error('The notification service did not respond.')), 15000);
    worker.addEventListener('statechange', changed);
    signal.addEventListener('abort', aborted, {once: true});
    if (signal.aborted) aborted();
    else changed();
  });
}

export function pushKeyMatches(subscription: PushSubscription, key: string): boolean {
  const existing = subscription.options.applicationServerKey;
  if (!existing) return false;
  const wanted = pushPublicKey(key);
  const actual = new Uint8Array(existing);
  return wanted.length === actual.length && wanted.every((byte, index) => byte === actual[index]);
}

export async function createPushSubscription(registration: ServiceWorkerRegistration, key: string, signal: AbortSignal): Promise<PushSubscription> {
  return new Promise((resolve, reject) => {
    let pending = true;
    const finish = () => {
      pending = false;
      clearTimeout(timeout);
      signal.removeEventListener('abort', aborted);
    };
    const aborted = () => {finish(); reject(new DOMException('Aborted', 'AbortError'));};
    const timeout = setTimeout(() => {finish(); reject(new Error('push-subscription-timeout'));}, 20000);
    signal.addEventListener('abort', aborted, {once: true});
    if (signal.aborted) {aborted(); return;}
    void registration.pushManager.subscribe({
      userVisibleOnly: true, applicationServerKey: pushPublicKey(key),
    }).then(subscription => {
      if (!pending) {void subscription.unsubscribe().catch(() => {}); return;}
      finish();
      resolve(subscription);
    }, error => {if (pending) {finish(); reject(error);}});
  });
}
