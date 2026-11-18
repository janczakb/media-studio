import {finishTreeOperation, type TreeOperation, type TreeOperationResult} from './tree-operations';
import {randomId} from './random-id';
import {createAssistantTransport} from './assistant/http';
import {createManagementTransport} from './management-http';
import {createUploadTransport} from './uploads';
import type { BrowseQuery, BrowseChoice, Entry, EntryDetails, EntryPage, EntryTagsUpdate, EntryUpdate, StudioTransport, SourceImportRun, SourceImportPage, Workspace, WorkspaceSettings } from './contracts';

export class StudioHttpError extends Error {
  constructor(readonly status: number, message: string) { super(message); this.name = 'StudioHttpError'; }
}

export function createHttpTransport(options: {baseUrl: string; csrfToken?: () => string; credentials?: RequestCredentials; onTreeOperation?: (operation:TreeOperation)=>void; navigationSubscribe?: (workspace: string, listener: () => void) => () => void}): StudioTransport {
  const base = options.baseUrl.replace(/\/$/, '');
  if (!/^https?:\/\//.test(base) && !base.startsWith('/')) throw new Error('An HTTP(S) or root-relative API URL is required.');
  const navigationListeners = new Map<string, Set<() => void>>();
  let channel: BroadcastChannel | null = null;
  function signalNavigation(workspace: string) {
    for (const listener of navigationListeners.get(workspace) ?? []) listener();
  }
  function subscribeNavigation(workspace: string, listener: () => void) {
    let listeners = navigationListeners.get(workspace);
    if (!listeners) {listeners = new Set(); navigationListeners.set(workspace, listeners);}
    listeners.add(listener);
    if (!channel && typeof BroadcastChannel !== 'undefined') {
      channel = new BroadcastChannel('fms.navigation.' + base);
      channel.onmessage = event => {if (typeof event.data === 'string') signalNavigation(event.data);};
    }
    const unsubscribeHost = options.navigationSubscribe?.(workspace, listener);
    return () => {
      unsubscribeHost?.();
      listeners!.delete(listener);
      if (!listeners!.size) navigationListeners.delete(workspace);
      if (!navigationListeners.size) {channel?.close(); channel = null;}
    };
  }
  async function request<T>(path: string, signal: AbortSignal, body?: BodyInit, method?: 'PATCH' | 'POST' | 'PUT' | 'DELETE'): Promise<T> {
    const headers = new Headers({Accept: 'application/json'});
    if (typeof body === 'string') headers.set('Content-Type', 'application/json');
    if ((body !== undefined || method !== undefined) && options.csrfToken) headers.set('X-CSRF-TOKEN', options.csrfToken());
    const response = await fetch(base + path, {signal, headers, credentials: options.credentials ?? 'same-origin', method: method ?? (body === undefined ? 'GET' : 'POST'), ...(body === undefined ? {} : {body})});
    const data: unknown = await response.json().catch(() => null);
    if (!response.ok) {
      const message = data && typeof data === 'object' && 'message' in data && typeof data.message === 'string' ? data.message : 'The request could not be completed.';
      throw new StudioHttpError(response.status, message);
    }
    if (body !== undefined || method !== undefined) {
      const workspace = /^\/workspaces\/([^/]+)/.exec(path)?.[1];
      if (workspace) {signalNavigation(decodeURIComponent(workspace)); channel?.postMessage(decodeURIComponent(workspace));}
    }
    return data as T;
  }
  function path(workspace: string, suffix: string): string { return `/workspaces/${encodeURIComponent(workspace)}/${suffix}`; }
  async function mutation<T>(workspace:string,url:string,signal:AbortSignal,body:string):Promise<T> {
    const initial=await request<T|TreeOperationResult>(url,signal,body);
    const result=await finishTreeOperation(initial,id=>request<TreeOperationResult>(path(workspace,`tree-operations/${encodeURIComponent(id)}/advance`),signal,'{}'),signal,options.onTreeOperation);
    if(result && typeof result==='object' && 'treeOperation' in result && !url.endsWith('/bulk'))return (result as TreeOperationResult).items[0] as T;
    return result as T;
  }
  function browseParams(query: BrowseQuery): URLSearchParams {
    const params = new URLSearchParams({view:query.view,search:query.search});
    if(query.limit!==undefined)params.set('limit',String(Math.max(1,Math.min(120,query.limit))));
    if(query.parent)params.set('parent',query.parent);
    if(query.sort)params.set('sort',query.sort);
    if(query.direction)params.set('direction',query.direction);
    if(query.thumbnailPreset)params.set('thumbnailPreset',query.thumbnailPreset);
    for(const [key,value] of Object.entries(query.photos??{}))if(value!==undefined)params.set(`photos[${key}]`,value);
    for(const [key,value] of Object.entries(query.filters??{})) {
      if (Array.isArray(value)) value.forEach((item,index)=>params.set(`filters[${key}][${index}]`, String(item)));
      else if (value !== undefined) params.set(`filters[${key}]`,String(value));
    }
    for(const [key,value] of Object.entries(query.customFilters??{}))params.set(`customFilters[${key}]`,value);
    return params;
  }

  return {
    treeOperations: {
      status:(workspace,id,signal)=>request(path(workspace,`tree-operations/${encodeURIComponent(id)}`),signal),
      advance:(workspace,id,signal)=>request(path(workspace,`tree-operations/${encodeURIComponent(id)}/advance`),signal,'{}'),
      cancel:(workspace,id,signal)=>request(path(workspace,`tree-operations/${encodeURIComponent(id)}/cancel`),signal,'{}'),
    },
    folders: {
      subscribe: subscribeNavigation,
      changes: (workspace, after, signal) => request(path(workspace, 'navigation/changes') + '?' + new URLSearchParams({after: String(after)}), signal),
      undo: (workspace, operation, signal) => request(path(workspace, `navigation/operations/${encodeURIComponent(operation)}/undo`), signal, '{}'),
      context: (workspace, folder, signal) => request(path(workspace, `folders/${encodeURIComponent(folder)}/context`), signal),
      ancestors: (workspace, folder, cursor, limit, signal) => request(path(workspace, `folders/${encodeURIComponent(folder)}/ancestors`) + '?' + new URLSearchParams({cursor, limit: String(Math.max(1, Math.min(100, limit)))}), signal),
    },
    assistant: createAssistantTransport(request, options),
    supportsBrowseControls: true,
    librarySearch: (workspace, query, cursor, signal) => {
      const params = browseParams({view:'files',parent:null,search:'',...(query.filters?{filters:query.filters}:{}),...(query.customFilters?{customFilters:query.customFilters}:{})});
      params.delete('view');params.delete('search');
      params.set('q',query.text);
      if(query.mode)params.set('mode',query.mode);
      if(query.kind)params.set('kind',query.kind);
      if(query.limit)params.set('limit',String(query.limit));
      if(cursor)params.set('cursor',cursor);
      return request(path(workspace,'library-search')+'?'+params,signal);
    },
    integrations: {
      approveExtension:(workspace,input,signal)=>request(path(workspace,'integrations/browser-extension/approve'),signal,JSON.stringify(input)),
      overview:(workspace,signal)=>request(path(workspace,'integrations'),signal),
      startSlackOAuth:(workspace,input,signal)=>request(path(workspace,'integrations/slack/oauth'),signal,JSON.stringify(input)),
      saveSlack:(workspace,input,signal)=>request(path(workspace,'integrations/slack'),signal,JSON.stringify(input),'PUT'),
      disconnectSlack:(workspace,revision,signal)=>request(path(workspace,'integrations/slack'),signal,JSON.stringify({revision}),'DELETE'),
    },
    apiAccess: {
      overview:(workspace,signal)=>request(path(workspace,'api-access'),signal),
      create:(workspace,input,signal)=>request(path(workspace,'api-access/tokens'),signal,JSON.stringify(input)),
      revoke:(workspace,token,signal)=>request(path(workspace,`api-access/tokens/${encodeURIComponent(token)}`),signal,undefined,'DELETE'),
    },
    aiInfo: signal => request('/ai', signal),
    aiLabels: {
      list:(workspace,state,cursor,signal)=>request(path(workspace,'ai/labels')+'?'+new URLSearchParams({state,...(cursor?{cursor}:{})}),signal),
      review:(workspace,id,state,revision,signal)=>request(path(workspace,'ai/labels/'+encodeURIComponent(id)),signal,JSON.stringify({state,revision}),'PATCH'),
    },
    searchIndex:(workspace,q,mode,cursor,signal)=>request(path(workspace,'search')+'?'+new URLSearchParams({q,mode,...(cursor?{cursor}:{})}),signal),
    applicationFiles: {
      sources: signal => request('/application-files', signal),
      browse: (source,query,cursor,signal) => {
        const params = new URLSearchParams({limit:'60',search:query.search});
        if(query.parent)params.set('parent',query.parent);
        if(cursor)params.set('cursor',cursor);
        if(source)params.set('source',source);
        return request('/application-files/catalog?'+params,signal);
      },
      thumbnailStatus: (ids,signal) => request('/application-files/catalog/thumbnail-status',signal,JSON.stringify({ids})),
      details: (source,entry,signal) => request('/application-files/catalog/entries/'+encodeURIComponent(entry),signal),
      neighbors: (source,entry,query,direction,signal,cursor) => {
        const params = new URLSearchParams({direction,search:query.search});
        if(cursor)params.set('cursor',cursor);
        if(source)params.set('source',source);
        return request('/application-files/catalog/entries/'+encodeURIComponent(entry)+'/neighbors?'+params,signal);
      },
      contentUrl: (source,entry,download=false) => base+'/application-files/catalog/entries/'+encodeURIComponent(entry)+'/original'+(download?'?download=1':''),
    },
    comments: {
      list:(w,e,filter,cursor,signal,options)=>request(path(w,`entries/${encodeURIComponent(e)}/comments`)+`?filter=${filter}`+(cursor?'&cursor='+encodeURIComponent(cursor):'')+(options?.anchorVersion?'&anchorVersion='+encodeURIComponent(options.anchorVersion):''),signal),
      create:(w,e,input,signal)=>request(path(w,`entries/${encodeURIComponent(e)}/comments`),signal,JSON.stringify({...input,...(input.document?{document:JSON.stringify(input.document)}:{})})),
      update:(w,e,id,input,signal)=>request(path(w,`entries/${encodeURIComponent(e)}/comments/${encodeURIComponent(id)}`),signal,JSON.stringify({...input,...(input.document?{document:JSON.stringify(input.document)}:{})}),'PATCH'),
      mentions:(w,e,search,signal)=>request(path(w,`entries/${encodeURIComponent(e)}/comment-mentions?search=${encodeURIComponent(search)}`),signal),
    },
    note: (workspace,entry,signal)=>request(path(workspace,`notes/${encodeURIComponent(entry)}`),signal),
    createNote: (workspace,input,signal)=>request(path(workspace,'notes'),signal,JSON.stringify(input)),
    updateNote: (workspace,entry,input,signal)=>request(path(workspace,`notes/${encodeURIComponent(entry)}`),signal,JSON.stringify(input),'PATCH'),
    workspaceCreationCandidates: (workspace,search,signal) => request(path(workspace,'workspace-creation-candidates')+'?search='+encodeURIComponent(search),signal),
    memberCandidates: (workspace, search, signal) => request(path(workspace,'member-candidates')+'?search='+encodeURIComponent(search),signal),
    assignMember: (workspace,value,signal) => request(path(workspace,'members'),signal,JSON.stringify(value)),
    updateMember: (workspace,id,role,signal) => request(path(workspace,`members/${encodeURIComponent(id)}`),signal,JSON.stringify({role}),'PATCH'),
    inviteMember: (workspace,value,signal) => request(path(workspace,'invitations'),signal,JSON.stringify(value)),
    invitations: (workspace,cursor,signal) => request(path(workspace,'invitations')+(cursor?'?after='+encodeURIComponent(cursor):''),signal),
    revokeInvitation: (workspace,id,signal) => request(path(workspace,`invitations/${encodeURIComponent(id)}/revoke`),signal,undefined,'POST'),
    members: (workspace, cursor, signal, search) => {
      const params = new URLSearchParams();
      if(cursor)params.set('cursor',cursor);
      if(search)params.set('search',search);
      return request(path(workspace,'members')+(params.size?'?'+params:''),signal);
    },
    removeMember: (workspace, member, signal) => request(path(workspace, `members/${encodeURIComponent(member)}/remove`),signal,undefined,'POST'),
    sourceImports:(workspace,signal)=>request<SourceImportPage>(path(workspace,'source-imports'),signal),
    startSourceImport:(workspace,mount,signal)=>request<SourceImportRun>(path(workspace,'source-imports'),signal,JSON.stringify({mount}),'POST'),
    stepSourceImport:(workspace,run,revision,signal)=>request<SourceImportRun>(path(workspace,`source-imports/${encodeURIComponent(run)}/step`),signal,JSON.stringify({revision}),'POST'),
    controlSourceImport:(workspace,run,revision,action,signal)=>request<SourceImportRun>(path(workspace,`source-imports/${encodeURIComponent(run)}`),signal,JSON.stringify({revision,action}),'PATCH'),
    management: createManagementTransport(options),
    videoEdit:(workspace,entry,signal)=>request(path(workspace,`entries/${encodeURIComponent(entry)}/video-edit`),signal),
    trimVideo:(workspace,entry,input,signal)=>request(path(workspace,`entries/${encodeURIComponent(entry)}/video-edit`),signal,JSON.stringify(input)),
    optimize: (workspace, entry, revision, signal, options) => request<Entry>(path(workspace, `entries/${encodeURIComponent(entry)}/optimize`), signal, JSON.stringify({revision, ...options})),
    settings: (workspace, signal) => request<WorkspaceSettings>(path(workspace, 'settings'), signal),
    updateSettings: (workspace, value, signal) => request<WorkspaceSettings>(path(workspace, 'settings'), signal, JSON.stringify(value), 'PATCH'),
    browseChoices: async (workspace, kind, values, signal) => {
      const params = new URLSearchParams({kind, limit:'60'});
      if(values.search)params.set('search',values.search);
      if(values.cursor)params.set('cursor',values.cursor);
      if(values.locale)params.set('locale',values.locale);
      const result = await request<{items:BrowseChoice[];nextCursor:string|null;hasMore:boolean}>(path(workspace,'filter-options')+'?'+params,signal);
      if(!Array.isArray(result.items)||result.items.length>60||!result.items.every(item=>typeof item.id==='string'&&typeof item.label==='string')||(result.nextCursor!==null&&typeof result.nextCursor!=='string')||typeof result.hasMore!=='boolean')throw new Error('Unsupported filter options response.');
      return result;
    },
    transfers: createUploadTransport(options),
    neighbors: (workspace, entry, query, direction, signal, cursor) => {
      const params = browseParams({...query,view:query.view==='trash'||query.view==='folders'?'files':query.view});
      params.set('direction',direction);
      if(query.direction)params.set('sortDirection',query.direction);
      if (cursor) params.set('cursor', cursor);
      return request<EntryPage>(path(workspace,`entries/${encodeURIComponent(entry)}/neighbors`)+ '?' + params,signal);
    },
    star: (workspace, entry, starred, signal) => request<{starred:boolean}>(path(workspace,`entries/${encodeURIComponent(entry)}/star`), signal, JSON.stringify({starred}), 'PUT'),
    visit: (workspace, entry, signal) => request<void>(path(workspace,`entries/${encodeURIComponent(entry)}/visit`), signal, undefined, 'POST'),
    bulk: (workspace, values, signal) => mutation<{items:Entry[];operationId?:string;undoAvailable?:boolean}>(workspace,path(workspace,'entries/bulk'), signal, JSON.stringify({...values,requestId:values.requestId??randomId()})),
    status: (workspace, ids, signal, options) => request<{items:Entry[]}>(path(workspace,'entries/status'),signal,JSON.stringify({ids,...(options?.thumbnailPreset?{thumbnailPreset:options.thumbnailPreset}:{})})),
    details: (workspace, entry, signal) => request<EntryDetails>(path(workspace, `entries/${encodeURIComponent(entry)}`), signal),
    replaceTags: (workspace, entry, revision, tags, signal) => request<EntryTagsUpdate>(
      path(workspace, `entries/${encodeURIComponent(entry)}/tags`), signal, JSON.stringify({revision, tags}), 'PATCH'),
    update: (workspace, entry, values: EntryUpdate, signal) => request<Entry>(path(workspace, `entries/${encodeURIComponent(entry)}`), signal, JSON.stringify(Object.keys(values).every(key=>['requestId','revision','name','parent'].includes(key))?{...values,requestId:values.requestId??randomId()}:values), 'PATCH'),
    trash: (workspace, entry, revision, signal) => mutation<Entry>(workspace,path(workspace, `entries/${encodeURIComponent(entry)}/trash`), signal, JSON.stringify({revision,requestId:randomId()})),
    restore: (workspace, entry, revision, signal) => mutation<Entry>(workspace,path(workspace, `entries/${encodeURIComponent(entry)}/restore`), signal, JSON.stringify({revision,requestId:randomId()})),
    workspace: (workspace, signal) => request<Workspace>(`/workspaces/${encodeURIComponent(workspace)}`, signal),
    workspaces: signal => request<{items: Workspace[]; canCreate: boolean}>('/workspaces', signal),
    retireWorkspace:(workspace,input,signal)=>request(`/workspaces/${encodeURIComponent(workspace)}/retirement`,signal,JSON.stringify(input),'POST'),
    workspaceOperation:(id,signal)=>request(`/workspace-operations/${encodeURIComponent(id)}`,signal),
    retryWorkspaceOperation:(id,signal)=>request(`/workspace-operations/${encodeURIComponent(id)}/retry`,signal,'{}','POST'),
    deleteWorkspace:(workspace,confirmName,signal)=>request(`/workspaces/${encodeURIComponent(workspace)}`,signal,JSON.stringify({confirmName}),'DELETE'),
    updateWorkspace: (workspace,name,signal,identity) => request<Workspace>(`/workspaces/${encodeURIComponent(workspace)}`,signal,JSON.stringify({name,...identity}),'PATCH'),
    makeDefaultWorkspace: (workspace,signal) => request<Workspace>(path(workspace,'make-default'),signal,JSON.stringify({})),
    createWorkspace: (name, signal, identity) => request<Workspace>('/workspaces', signal, JSON.stringify({name,...identity})),
    browse: async (workspace: string, query: BrowseQuery, cursor, signal) => {
      const params = browseParams(query);
      if (cursor) params.set('cursor', cursor);
      const result = await request<EntryPage>(path(workspace, 'entries') + '?' + params, signal);
      if (!Array.isArray(result.items) || result.items.length > 120 || !result.items.every(e => typeof e.id === 'string' && typeof e.name === 'string' && (e.kind === 'file' || e.kind === 'folder')) || (result.nextCursor !== null && typeof result.nextCursor !== 'string') || (result.previousCursor !== null && typeof result.previousCursor !== 'string')) throw new Error('Unsupported listing response.');
      return result;
    },
    createFolder: (workspace, parent, name, signal) => request<Entry>(path(workspace, 'folders'), signal, JSON.stringify({parent, name})),
    upload: async (workspace, parent, file, key, signal) => {
      const body = new FormData();
      body.append('file', file);
      if (parent) body.append('parent', parent);
      const headers = new Headers({Accept: 'application/json', 'Idempotency-Key': key});
      if (options.csrfToken) headers.set('X-CSRF-TOKEN', options.csrfToken());
      const response = await fetch(base + path(workspace, 'uploads'), {method: 'POST', body, signal, headers, credentials: options.credentials ?? 'same-origin'});
      const data: unknown = await response.json().catch(() => null);
      if (!response.ok) throw new StudioHttpError(response.status, data && typeof data === 'object' && 'message' in data && typeof data.message === 'string' ? data.message : 'Upload failed.');
      return data as Entry;
    },
    archiveUrl: (workspace, entries) => {
      const params = new URLSearchParams();
      entries.forEach((id, index) => params.set(`entries[${index}]`, id));
      return base + path(workspace, 'download') + '?' + params;
    },
    createArchive: (workspace, entries, signal) => request(path(workspace, 'archive-jobs'), signal, JSON.stringify({entries})),
    archiveJobs: (workspace, signal) => request(path(workspace, 'archive-jobs'), signal),
    archiveResultUrl: (workspace, job) => base + path(workspace, `archive-jobs/${encodeURIComponent(job)}/result`),
    contentUrl: (workspace, entry, download = false, versionId) => {
      const version = versionId ? `/versions/${encodeURIComponent(versionId)}` : '';
      return base + path(workspace, `entries/${encodeURIComponent(entry)}${version}/content`) + (download ? '?download=1' : '');
    },
  };
}
