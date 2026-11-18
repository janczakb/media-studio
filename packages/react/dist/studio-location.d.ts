import type { BrowseQuery } from './contracts';
export type StudioView = Exclude<BrowseQuery['view'], 'folders'> | 'shares' | 'tags' | 'notifications' | 'members' | 'application' | 'ai' | 'integrations';
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
export declare function readStudioLocation(url: string): StudioLocation;
export declare function studioUrl(url: string, location: StudioLocation): URL;
export declare function defaultStudioLocation(): StudioLocation;
