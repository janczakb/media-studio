export declare const pushEvents: readonly ["mentioned", "uploaded", "guest_uploaded", "processed", "trashed", "restored", "purged"];
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
    preferences(workspace: string, events: PushEvent[], signal: AbortSignal): Promise<{
        events: PushEvent[];
    }>;
    subscribe(workspace: string, subscription: PushSubscriptionJSON, path: string, locale: string, signal: AbortSignal): Promise<{
        subscribed: boolean;
    }>;
    unsubscribe(workspace: string, device: string, signal: AbortSignal): Promise<{
        subscribed: boolean;
    }>;
}
export declare function pushDeviceId(subscription: PushSubscription): Promise<string>;
export declare function pushPublicKey(value: string): Uint8Array<ArrayBuffer>;
export declare function activatePushWorker(registration: ServiceWorkerRegistration, signal: AbortSignal): Promise<void>;
export declare function pushKeyMatches(subscription: PushSubscription, key: string): boolean;
export declare function createPushSubscription(registration: ServiceWorkerRegistration, key: string, signal: AbortSignal): Promise<PushSubscription>;
