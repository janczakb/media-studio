import type {ManagementTransport} from './management-contracts';
import type {TransferTransport} from './uploads';
export interface WorkspaceIdentity {emoji?:string|null;kind?:'personal'|'team';requestKey?:string}
export interface Workspace { indexedSearch?:boolean; semanticSearch?:boolean; emoji?:string|null;workspaceType?:'personal'|'team'; commentsEnabled?:boolean; isDefault?:boolean; permissions?: Record<string, boolean>; id: string; name: string; role: 'owner' | 'admin' | 'editor' | 'viewer'; updatedAt?: string | null; backgroundArchives?: boolean; guestUploads?: boolean }
export interface WorkspaceMember {id:string; name:string|null; email?:string|null; avatarUrl?:string|null; role:string; canRemove:boolean}
export interface MemberPage {items:WorkspaceMember[]; nextCursor:string|null; hasMore:boolean}
export interface Entry {
  operationId?: string;
  undoAvailable?: boolean;
  sourceKey?: string;
  sourceLabel?: string;
  noteExcerpt?:string;
  noteBackground?:string;
  id: string;
  name: string;
  kind: 'file' | 'folder';
  /** Present on folder browse responses; only active managed child folders count. */
  hasChildFolders?: boolean;
  mediaType: string | null;
  audioWaveform?: string | null;
  mimeType: string | null;
  size: number | null;
  versionId: string | null;
  updatedAt: string;
  createdAt?: string;
  revision: number;
  trashedAt: string | null;
  thumbnailUrl?: string | null;
  /** Omitted by legacy transports. Null keeps the thumbnail until a preview is available. */
  previewUrl?: string | null;
  previewState?: string;
  thumbnailState?: string;
  renditionsRefreshing?: boolean;
  readOnly?: boolean;
  starred?: boolean;
  width?: number | null;
  height?: number | null;
}
export type DrawingPoint = [number, number];
export interface DrawingStyle {
  strokeWidth?: number;
  opacity?: number;
  fill?: 'none' | 'semi' | 'solid' | 'pattern';
  dash?: 'solid' | 'dashed' | 'dotted';
  fontSize?: number;
  fontFamily?: 'sans-serif' | 'serif' | 'monospace' | 'cursive';
  textAlign?: 'start' | 'middle' | 'end';
  verticalAlign?: 'start' | 'middle' | 'end';
}
export interface DrawingMark extends DrawingStyle {
  tool: 'pen' | 'arrow' | 'line' | 'rectangle' | 'ellipse' | 'text';
  color: string;
  points: DrawingPoint[];
  text?: string;
}
export interface ImageDrawing {versionId: string; marks: DrawingMark[]}
export interface EntryMetadata {drawing?: ImageDrawing;alt?: string | null; caption?: string | null; description?: string | null}
export interface EntryTag {id: string; label: string; locale: string}
export type EntryTagInput = {id: string} | {name: string; locale?: string};
export interface EntryTagsUpdate {revision: number; updatedAt?: string; tags: EntryTag[]}
export interface EntryDetails extends Entry {
  parentName?: string | null;
  parentId: string | null;
  metadata: EntryMetadata;
  createdAt: string;
  tags?: EntryTag[];
  canEditTags?: boolean;
  imageInsights?: {
    analysis?: {description: string; tags: string[]; sample: boolean};
    ocr?: {text: string; partial: boolean};
  };
  renditions?: {name: string; mimeType: string; bytes: number; width: number | null; height: number | null}[];
}
export interface EntryUpdate {requestId?: string;drawing?: ImageDrawing; revision: number; name?: string; parent?: string | null; metadata?: EntryMetadata}
export interface EntryPage {
  items: Entry[];
  nextCursor: string | null;
  previousCursor: string | null;
  hasMore: boolean;
  canWrite: boolean;
}
export type BrowseFileType = 'image' | 'video' | 'audio' | 'pdf' | 'document' | 'other';
export type BrowseCategory = 'code' | 'document' | 'spreadsheet' | 'presentation' | 'image' | 'video' | 'audio' | 'archive' | 'font' | 'other';
export type BrowseSort = string;
export type BrowseDirection = 'asc' | 'desc';
export interface BrowseFilters {
  dateFrom?: string;
  dateTo?: string;
  minBytes?: number;
  maxBytes?: number;
  type?: BrowseFileType;
  types?: BrowseCategory[];
  extensions?: string[];
  mimeTypes?: readonly string[];
  tagIds?: string[];
  uploaderIds?: string[];
  tagMatch?: 'any' | 'all';
  tagLocale?: string;
}
export interface BrowseQuery {
  thumbnailPreset?: string;
  /** Bounded page size, up to 120. */
  limit?: number;
  parent: string | null;
  view: 'files' | 'photos' | 'trash' | 'folders' | 'starred' | 'recent' | 'tagged';
  search: string;
  photos?: {kind: 'all'|'image'|'video'|'starred';period:'years'|'months'|'days';bucket?:string};
  filters?: BrowseFilters;
  customFilters?: Record<string, string>;
  sort?: BrowseSort;
  direction?: BrowseDirection;
}
export interface BulkEntry {id: string; revision: number}
export type BulkEntryRequest = ({requestId?: string} & (
  | {operation: 'move'; items: BulkEntry[]; parent: string | null}
  | {operation: 'trash' | 'restore'; items: BulkEntry[]}));
export interface MediaReference { workspaceId?: string; assetId: string; versionId: string }
export interface WorkspaceSettings {
  revision: number;
  extensions: string[];
  groups: Record<string, string[]>;
  chunks: boolean;
  chunksAvailable: boolean;
  chunkMaxMiB?: number | null;
  transferPolicy?: {serverMaxMiB: number; chunkBytes: number; maxParts: number; maxConcurrent: number; multipartThresholdBytes: number | null};
  canManage: boolean;
  processing?: {
    images: {name: string; width: number; height: number; format: string; quality: number; stripMetadata?: boolean}[];
    video: {compress: boolean; poster: string | null};
  };
}
export type WorkspaceSettingsUpdate = Pick<WorkspaceSettings, 'revision' | 'extensions' | 'chunks' | 'chunkMaxMiB'>;
export interface NotePayload {entry:Entry|null;document:Record<string,unknown>;background:string}
export interface NoteInput {title:string;background:string;document:Record<string,unknown>;parent?:string|null;key?:string;revision?:number}
export interface VideoEditOperation {id:string;state:'queued'|'processing'|'ready'|'failed';error:string|null;entryId:string}
export interface VideoEditCapabilities {enabled:boolean;mode:'queue'|'sync';maxDuration:number;minSelection:number;operation:VideoEditOperation|null}
export interface PreviewAction {id:string;separatorBefore?:boolean;label?:string;icon?:typeof import('@hugeicons/core-free-icons').Delete02Icon;visible?:(entry:Entry)=>boolean;onPress?:(context:{entry:Entry;workspace:string})=>void}
export interface SourceImportRun {canAdvance:boolean;id:string;mount:string;state:'ready'|'running'|'paused'|'completed'|'failed'|'cancelled';revision:number;processed:number;progress:number}
export interface SourceImportPage {sources:{key:string;label:string;canImport:boolean}[];items:SourceImportRun[]}
export interface AiLabel {id:string;name:string;score:number|null;state:'pending'|'accepted'|'rejected';revision:number;provider:string;entryId:string;fileName:string}
export interface AiLabelPage {items:AiLabel[];nextCursor:string|null;hasMore:boolean;canReview:boolean}
export interface AiSearchPage {items:{id:string;name:string;mediaType:string|null;versionId:string|null}[];nextCursor:string|null;hasMore:boolean;rankingMayChange:boolean}
export interface AiConfiguration {
  assistant?: import('./assistant/contracts').AssistantConfiguration;
  api?: {enabled:boolean;canUse:boolean};
  search?: {enabled:boolean};
  mcp: {installed:boolean;enabled:boolean;canUse:boolean;endpoint:string|null;authentication:string;tools:string[]};
  features: Record<'imageTags'|'documentText'|'imageOcr'|'videoAnalysis'|'semanticSearch',{implemented:boolean;enabled:boolean}>;
}
export interface WorkspaceOperation {id:string;workspace:string;mode:'move'|'delete';target:string|null;state:'queued'|'working'|'failed'|'completed';phase:string;processed:number;error:string|null;}

export interface StudioTransport {
  treeOperations?: import('./tree-operations').TreeOperationTransport;
  folders?: import('./folders/contracts').FolderNavigationTransport;
  librarySearch?: import('./search/contracts').LibrarySearchTransport;
  assistant?: import('./assistant/contracts').AssistantTransport;
  integrations?: import('./integrations').IntegrationsTransport;
  apiAccess?: import('./api-access').ApiAccessTransport;
  aiInfo?(signal:AbortSignal):Promise<AiConfiguration>;
  aiLabels?: {list:(workspace:string,state:string,cursor:string|null,signal:AbortSignal)=>Promise<AiLabelPage>;review:(workspace:string,id:string,state:AiLabel['state'],revision:number,signal:AbortSignal)=>Promise<Pick<AiLabel,'id'|'state'|'revision'>>};
  searchIndex?(workspace:string,q:string,mode:'lexical'|'hybrid'|'semantic',cursor:string|null,signal:AbortSignal):Promise<AiSearchPage>;
  applicationFiles?: import('./application-files').ApplicationFilesTransport;
  sourceImports?(workspace:string,signal:AbortSignal):Promise<SourceImportPage>;
  startSourceImport?(workspace:string,mount:string,signal:AbortSignal):Promise<SourceImportRun>;
  stepSourceImport?(workspace:string,run:string,revision:number,signal:AbortSignal):Promise<SourceImportRun>;
  controlSourceImport?(workspace:string,run:string,revision:number,action:'pause'|'resume'|'cancel',signal:AbortSignal):Promise<SourceImportRun>;
  comments?: CommentTransport;
  videoEdit?(workspace:string,entry:string,signal:AbortSignal):Promise<VideoEditCapabilities>;
  trimVideo?(workspace:string,entry:string,input:{revision:number;start:number;end:number;requestId:string},signal:AbortSignal):Promise<VideoEditOperation>;
  note?(workspace:string,entry:string,signal:AbortSignal):Promise<NotePayload>;
  createNote?(workspace:string,input:NoteInput,signal:AbortSignal):Promise<NotePayload>;
  updateNote?(workspace:string,entry:string,input:NoteInput,signal:AbortSignal):Promise<NotePayload>;
  retireWorkspace?(workspace:string,input:{confirmName:string;mode:'move'|'delete';target?:string},signal:AbortSignal):Promise<WorkspaceOperation>;
  workspaceOperation?(id:string,signal:AbortSignal):Promise<WorkspaceOperation>;
  retryWorkspaceOperation?(id:string,signal:AbortSignal):Promise<WorkspaceOperation>;
  deleteWorkspace?(workspace:string,confirmName:string,signal:AbortSignal):Promise<{deleted:boolean}>;
  updateWorkspace?(workspace:string,name:string,signal:AbortSignal,identity?:Pick<WorkspaceIdentity,'emoji'>):Promise<Workspace>;
  makeDefaultWorkspace?(workspace:string,signal:AbortSignal):Promise<Workspace>;
  workspaceCreationCandidates?(workspace:string,search:string,signal:AbortSignal):Promise<{items:(WorkspaceMember & {type:string})[]}>;
  memberCandidates?(workspace:string, search:string, signal:AbortSignal):Promise<{items:(WorkspaceMember & {type:string})[]}>;
  assignMember?(workspace:string, value:{id:string;type:string;role:string}, signal:AbortSignal):Promise<unknown>;
  updateMember?(workspace:string, id:string, role:string, signal:AbortSignal):Promise<unknown>;
  inviteMember?(workspace:string, value:{email:string;role:string}, signal:AbortSignal):Promise<unknown>;
  invitations?(workspace:string, cursor:string|null, signal:AbortSignal):Promise<{items:{id:string;email:string;role:string;expires_at:string;accepted_at:string|null;revoked_at:string|null}[];nextCursor:string|null}>;
  revokeInvitation?(workspace:string, id:string, signal:AbortSignal):Promise<unknown>;
  members?(workspace:string, cursor:string|null, signal:AbortSignal, search?:string):Promise<MemberPage>;
  removeMember?(workspace:string, member:string, signal:AbortSignal):Promise<{removed:boolean}>;
  optimize?(workspace: string, entry: string, revision: number, signal: AbortSignal, options?: {replace: boolean; retention: 'keep' | 'prune'}): Promise<Entry>;
  settings?(workspace: string, signal: AbortSignal): Promise<WorkspaceSettings>;
  updateSettings?(workspace: string, value: WorkspaceSettingsUpdate, signal: AbortSignal): Promise<WorkspaceSettings>;
  transfers?: TransferTransport;
  management?: ManagementTransport;
  supportsBrowseControls?: boolean;
  browseChoices?(workspace: string, kind: 'extensions' | 'tags' | 'tagLocales', options: {search?: string; cursor?: string; locale?: string}, signal: AbortSignal): Promise<{items: BrowseChoice[]; nextCursor: string|null; hasMore: boolean}>;
  star?(workspace: string, entry: string, starred: boolean, signal: AbortSignal): Promise<{starred: boolean}>;
  visit?(workspace: string, entry: string, signal: AbortSignal): Promise<void>;
  bulk?(workspace: string, request: BulkEntryRequest, signal: AbortSignal): Promise<{items: Entry[]; operationId?:string; undoAvailable?:boolean}>;
  neighbors(workspace: string, entry: string, query: BrowseQuery, direction: 'next' | 'previous', signal: AbortSignal, cursor?: string): Promise<EntryPage>;
  status(workspace: string, ids: string[], signal: AbortSignal, options?: {thumbnailPreset?: string}): Promise<{items: Entry[]}>;
  details(workspace: string, entry: string, signal: AbortSignal): Promise<EntryDetails>;
  replaceTags?(workspace: string, entry: string, revision: number, tags: EntryTagInput[], signal: AbortSignal): Promise<EntryTagsUpdate>;
  update(workspace: string, entry: string, values: EntryUpdate, signal: AbortSignal): Promise<Entry>;
  trash(workspace: string, entry: string, revision: number, signal: AbortSignal): Promise<Entry>;
  restore(workspace: string, entry: string, revision: number, signal: AbortSignal): Promise<Entry>;
  workspace?(id: string, signal: AbortSignal): Promise<Workspace>;
  workspaces(signal: AbortSignal): Promise<{items: Workspace[]; canCreate: boolean;workspaceIdentity?:boolean;workspacesEnabled?:boolean;workspaceLimit?:number;workspaceCreationAllowed?:boolean;appearance?:{primaryColor?:string};viewer?:{name?:string|null;email?:string|null;avatarUrl?:string|null}}>;
  createWorkspace(name: string, signal: AbortSignal, identity?:WorkspaceIdentity): Promise<Workspace>;
  browse(workspace: string, query: BrowseQuery, cursor: string | null, signal: AbortSignal): Promise<EntryPage>;
  createFolder(workspace: string, parent: string | null, name: string, signal: AbortSignal): Promise<Entry>;
  upload(workspace: string, parent: string | null, file: File, key: string, signal: AbortSignal): Promise<Entry>;
  contentUrl(workspace: string, entry: string, download?: boolean, versionId?: string): string;
  archiveUrl?(workspace: string, entries: string[]): string;
  createArchive?(workspace: string, entries: string[], signal: AbortSignal): Promise<{id:string;state:string}>;
  archiveJobs?(workspace: string, signal: AbortSignal): Promise<{items:{id:string;state:string;expires_at:string}[]}>;
  archiveResultUrl?(workspace: string, job: string): string;
}
export interface BrowseChoice {id: string; label: string}
export interface BrowseFilterExtension extends BrowseChoice {options: BrowseChoice[]}
export interface BrowseExtensions {
  filters?: BrowseFilterExtension[];
  sorters?: BrowseChoice[];
}
export interface StudioOptions {
  /** Change on authenticated principal/tenant changes to discard instance state and caches. */
  identityScope?: string;
  layout?: import('./components/layout/layout-config').StudioLayoutOptions;
  transport: StudioTransport;
  stylesheetUrl?: string;
  /** Pre-rendered loader outside the mount target. Studio removes it after revealing the ready view. */
  startupElement?: HTMLElement;
  workspaceId?: string;
  workspaceIds?: string[];
  locale?: string;
  shortcuts?: false | import('./shortcuts/registry').ShortcutOptions;
  localization?:import('./workspace-i18n').StudioLocalization;
  theme?: 'light' | 'dark';
  onThemeChange?: (theme: 'light' | 'dark') => void;
  onAccountSecurity?:()=>void;
  onLogout?:()=>void;
  user?: {name?: string; email?: string; avatarUrl?: string};
  mode?: 'library' | 'picker';
  /** Exact allowed MIME types in picker mode. Folders remain navigable. */
  pickerMimeTypes?: readonly string[] | null;
  pickerInitialFolder?: string | null;
  pickerScopedFolder?: string | null;
  syncUrl?: boolean;
  browse?: BrowseExtensions;
  upload?: {enabled?: boolean; allowFolders?: boolean; showPanel?: boolean; panelInitiallyExpanded?: boolean; concurrency?: number; maxFiles?: number; maxRetainedCompleted?: number; progressIntervalMs?: number; chunkThresholdBytes?: number};
  videoEditing?: {timelineThumbnails?:boolean;thumbnailCount?:number;showPlayerControls?:boolean};
  comments?: boolean;
  previewActions?: false | PreviewAction[];
  /** Header menu actions resolved for the selected file. Built-in print obeys download permission. */
  previewMenu?: false | PreviewAction[] | ((entry: Entry) => PreviewAction[]);
  /** Office previews are lazy, read-only and bounded. False disables Office, iWork, OpenDocument, RTF, CSV and Markdown readers. */
  pdfPreview?: false | import('./components/library/PDFPreview').PDFPreviewSettings;
  documentPreview?: false | import('./office-preview-contract').OfficePreviewSettings;
  onPreviewReady?:(handle:import('./preview-contract').FilePreviewHandle|null)=>void;
  previewTitleEditable?:boolean;
  imagePreview?:import('./image-preview/contracts').ImagePreviewSettings;
  videoPreviewSkin?: 'default' | 'neutral';
  primaryColor?:string;
  branding?: {logoUrl?: string; logoLabel?: string};
  pickerSelectionLimit?: number;
  pickerMultipleSelection?: boolean;
  pickerInitialSelection?: readonly MediaReference[];
  pickerThumbnailPreset?: string | null;
  onPickerSelectionChange?: (references: MediaReference[]) => void;
  onSelectionReset?: () => void;
  onSelect?: (reference: MediaReference, entry: Entry) => void;
}

export interface ImageCommentAnchor {versionId:string;x:number;y:number}
export interface FileComment {anchor?:ImageCommentAnchor|null;document?:CommentRun[]|null;id:string;body:string;mentions:string[];revision:number;author:{name:string|null;avatarUrl:string|null};createdAt:string;updatedAt:string;resolved:boolean;canEdit:boolean;canResolve:boolean}
export interface CommentPage {items:FileComment[];nextCursor:string|null;hasMore:boolean;canCreate:boolean}
export interface CommentTransport {
  list(workspace:string,entry:string,filter:string,cursor:string|null,signal:AbortSignal,options?:{anchorVersion:string}):Promise<CommentPage>;
  create(workspace:string,entry:string,input:{body:string;mentions:string[];key:string;document?:CommentRun[];anchor?:ImageCommentAnchor},signal:AbortSignal):Promise<FileComment>;
  update(workspace:string,entry:string,id:string,input:{revision:number;body?:string;mentions?:string[];document?:CommentRun[];resolved?:boolean;delete?:boolean},signal:AbortSignal):Promise<FileComment|{deleted:true}>;
  mentions(workspace:string,entry:string,search:string,signal:AbortSignal):Promise<{items:{id:string;principalType:string;memberId:string;name:string|null;avatarUrl:string|null}[]}>;
}

export type CommentRun={type:'text';text:string}|{type:'mention';id:string;principalType:string;name?:string|null};
