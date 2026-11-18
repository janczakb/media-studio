import type {BrowseQuery} from './contracts';

const views = ['files', 'photos', 'starred', 'recent', 'trash', 'tagged', 'shares', 'tags', 'notifications', 'members', 'application', 'ai', 'integrations'] as const;
export type StudioView = Exclude<BrowseQuery['view'], 'folders'> | 'shares' | 'tags' | 'notifications' | 'members' | 'application' | 'ai' | 'integrations';
const keys = {integration:'fmsIntegration', aiTab:'fmsAiTab', view: 'fmsView', workspace: 'fmsWorkspace', folder: 'fmsFolder', file: 'fmsFile', media: 'fmsMedia', period: 'fmsPeriod', tag: 'fmsTag', source: 'fmsSource', applicationFolder: 'fmsApplicationFolder'} as const;
export type StudioLocation = {
  view: StudioView;
  integration?: 'slack' | null;
  aiTab?: 'connection' | 'api' | null;
  source?: string | null;
  applicationFolder?: string | null;
  workspace: string | null;
  folder: string | null;
  tag?: string | null;
  file: string | null;
  media: NonNullable<BrowseQuery['photos']>['kind'];
  period: NonNullable<BrowseQuery['photos']>['period'];
};

export function readStudioLocation(url: string): StudioLocation {
  const params = new URL(url).searchParams;
  const id = (key: string) => {
    const value = params.get(key);
    return value && /^[A-Za-z0-9_-]{1,128}$/.test(value) ? value : null;
  };
  const view = views.find(value => value === params.get(keys.view)) ?? 'files';
  const media = (['all', 'image', 'video', 'starred'] as const).find(value => value === params.get(keys.media)) ?? 'all';
  const period = (['years', 'months', 'days'] as const).find(value => value === params.get(keys.period)) ?? 'days';
  return {view, integration:view==='integrations'&&params.get(keys.integration)==='slack'?'slack':null, aiTab:view==='ai'&&params.get(keys.aiTab)==='api'?'api':null, source: view==='application' ? id(keys.source) : null, applicationFolder: view==='application' ? id(keys.applicationFolder) : null, tag: view === 'tagged' ? id(keys.tag) : null, workspace: view==='application' ? null : id(keys.workspace), folder: view === 'files' ? id(keys.folder) : null, file: view === 'trash' || view === 'ai' || view === 'integrations' ? null : id(keys.file), media, period};
}

export function studioUrl(url: string, location: StudioLocation): URL {
  const next = new URL(url);
  for (const key of Object.values(keys)) next.searchParams.delete(key);
  if(location.view==='integrations'&&location.integration)next.searchParams.set(keys.integration,location.integration);
  if(location.view==='ai'&&location.aiTab==='api')next.searchParams.set(keys.aiTab,'api');
  if (location.view !== 'files') next.searchParams.set(keys.view, location.view);
  if (location.view === 'application') {
    if(location.source)next.searchParams.set(keys.source,location.source);
    if(location.applicationFolder)next.searchParams.set(keys.applicationFolder,location.applicationFolder);
  }
  if (location.workspace && location.view !== 'application') next.searchParams.set(keys.workspace, location.workspace);
  if (location.folder && location.view === 'files') next.searchParams.set(keys.folder, location.folder);
  if (location.file && location.view !== 'trash' && location.view !== 'ai' && location.view !== 'integrations') next.searchParams.set(keys.file, location.file);
  if (location.view === 'tagged' && location.tag) next.searchParams.set(keys.tag, location.tag);
  if (location.view === 'photos') {
    if (location.media !== 'all') next.searchParams.set(keys.media, location.media);
    if (location.period !== 'days') next.searchParams.set(keys.period, location.period);
  }
  return next;
}

export function defaultStudioLocation(): StudioLocation {
  return {view: 'files', workspace: null, folder: null, file: null, media: 'all', period: 'days'};
}
