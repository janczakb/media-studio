import {finishTreeOperation,type TreeOperationResult} from './tree-operations';
import type {ManagementTransport, CursorPage, ShareRecord, ShareCreated, ShareAnalytics, SharePage, StudioNotification, StudioTag, TrashPage, TrashRecord} from './management-contracts';
import type {Entry} from './contracts';
import {StudioHttpError} from './http';

export function createManagementTransport(options: {baseUrl: string; csrfToken?: () => string; credentials?: RequestCredentials}): ManagementTransport {
  const base = options.baseUrl.replace(/\/$/, '');
  if (!/^https?:\/\//.test(base) && !base.startsWith('/')) throw new Error('An HTTP(S) or root-relative API URL is required.');
  const path = (workspace: string, suffix: string) => '/workspaces/' + encodeURIComponent(workspace) + '/' + suffix;
  async function request<T>(url: string, signal: AbortSignal, body?: object, method?: 'POST' | 'PATCH' | 'PUT' | 'DELETE'): Promise<T> {
    const headers = new Headers({Accept: 'application/json'});
    if (body !== undefined) headers.set('Content-Type', 'application/json');
    if (method && options.csrfToken) headers.set('X-CSRF-TOKEN', options.csrfToken());
    const response = await fetch(base + url, {signal, headers, credentials: options.credentials ?? 'same-origin',
      method: method ?? 'GET', ...(body === undefined ? {} : {body: JSON.stringify(body)})});
    const data: unknown = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
      const message = data && typeof data === 'object' && 'message' in data && typeof data.message === 'string'
        ? data.message : 'The request could not be completed.';
      throw new StudioHttpError(response.status, message);
    }
    return data as T;
  }
  function query(values: Record<string, string | boolean | undefined>, cursor: string | null) {
    const params = new URLSearchParams({limit: '60'});
    for (const [key, value] of Object.entries(values)) {
      if (value !== undefined) params.set(key, value === true ? '1' : value === false ? '0' : value);
    }
    if (cursor) params.set('cursor', cursor);
    return '?' + params;
  }
  async function page<T, P extends CursorPage<T>>(url: string, signal: AbortSignal): Promise<P> {
    const result = await request<P>(url, signal);
    if (!result || !Array.isArray(result.items) || result.items.length > 60
      || (result.nextCursor !== null && typeof result.nextCursor !== 'string')
      || (result.previousCursor != null && typeof result.previousCursor !== 'string')
      || typeof result.hasMore !== 'boolean') throw new Error('Unsupported management response.');
    return result;
  }
  return {
    browserPush: {
      workerUrl: base + '/push/worker.js',
      status: (workspace, device, signal) => request(path(workspace, 'notification-push') + (device ? '?device=' + encodeURIComponent(device) : ''), signal),
      preferences: (workspace, events, signal) => request(path(workspace, 'notification-push/preferences'), signal, {events}, 'PUT'),
      subscribe: (workspace, subscription, returnPath, locale, signal) => request(path(workspace, 'notification-push/devices'), signal, {...subscription, path: returnPath, locale}, 'POST'),
      unsubscribe: (workspace, device, signal) => request(path(workspace, 'notification-push/devices/' + encodeURIComponent(device)), signal, undefined, 'DELETE'),
    },
    inviteShare: (workspace, input, signal) => request(path(workspace, 'shares/invitations'), signal, input, 'POST'),
    shareLink: (workspace, id, signal) => request(path(workspace, 'shares/' + encodeURIComponent(id) + '/link'), signal),
    shareAnalytics: (workspace, id, days, signal) => request<ShareAnalytics>(path(workspace, 'shares/' + encodeURIComponent(id) + '/analytics?days=' + days), signal),
    deleteShare: (workspace, id, revision, signal) => request(path(workspace, 'shares/' + encodeURIComponent(id)), signal, {revision}, 'DELETE'),
    shares: (workspace, values, cursor, signal) => page<ShareRecord, SharePage>(path(workspace, 'shares') + query({...values}, cursor), signal),
    createShare: (workspace, input, signal) => request<ShareCreated>(path(workspace, 'shares'), signal, input, 'POST'),
    updateShare: (workspace, id, input, signal) => request<ShareRecord>(path(workspace, 'shares/' + encodeURIComponent(id)), signal, input, 'PATCH'),
    rotateShare: (workspace, id, revision, signal) => request<ShareCreated>(path(workspace, 'shares/' + encodeURIComponent(id) + '/rotate'), signal, {revision}, 'POST'),
    trashReport: (workspace, values, cursor, signal) => page<TrashRecord, TrashPage>(path(workspace, 'trash-report') + query({...values}, cursor), signal),
    restoreEntry: async (workspace, id, revision, signal) => {
      const initial=await request<Entry|TreeOperationResult>(path(workspace,'entries/'+encodeURIComponent(id)+'/restore'),signal,{revision},'POST');
      const result=await finishTreeOperation(initial,operation=>request<TreeOperationResult>(path(workspace,'tree-operations/'+encodeURIComponent(operation)+'/advance'),signal,{},'POST'),signal);
      return 'treeOperation' in result?result.items[0]!:result;
    },
    readVisibleNotifications:(workspace,ids,signal)=>request(path(workspace,'notifications/read-visible'),signal,{ids},'POST'),
    notifications: (workspace, unread, cursor, signal) => page<StudioNotification, CursorPage<StudioNotification>>(path(workspace, 'notifications') + query({unread}, cursor), signal),
    readNotification: (workspace, id, read, signal) => request<Pick<StudioNotification,'id'|'readAt'>>(path(workspace, 'notifications/' + encodeURIComponent(id)), signal, {read}, 'PATCH'),
    readAllNotifications: (workspace, signal) => request(path(workspace, 'notifications/read-all'), signal, {}, 'POST'),
    tags: (workspace, search, cursor, signal) => page<StudioTag, CursorPage<StudioTag>>(path(workspace, 'tags') + query({search}, cursor), signal),
  };
}
