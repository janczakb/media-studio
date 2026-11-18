import type { AssistantTransport } from './contracts';
export declare function createAssistantTransport(request: <T>(path: string, signal: AbortSignal, body?: BodyInit, method?: 'PATCH' | 'POST' | 'PUT' | 'DELETE') => Promise<T>, options: {
    baseUrl: string;
    credentials?: RequestCredentials;
}): AssistantTransport;
