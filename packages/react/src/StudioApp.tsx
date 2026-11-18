import {ShortcutRegistry,readShortcutPreferences,resolveShortcutBindings,type ShortcutBindings} from './shortcuts/registry';
import {ShortcutContext,useShortcutRegistry} from './shortcuts/context';
import {clipboardFiles,clipboardLimits,copyImageToClipboard,readClipboardFiles} from './shortcuts/clipboard';
import {BasicSearchSpotlight} from './search/BasicSearchSpotlight';
import {NavigationSync} from './folders/NavigationSync';
import {FolderNavigationClient} from './folders/FolderNavigationClient';
import {FolderBreadcrumbs} from './folders/FolderBreadcrumbs';
import {createFolderTreeTransport,type FolderSummary} from './folders/FolderTreeClient';
import {LibrarySearch} from './search/LibrarySearch';
import {SelectionBar,type SelectionAction} from './components/library/SelectionBar';
import {CreateDestinationDialog} from './components/library/CreateDestinationDialog';
import {LibraryCreateOverlay} from './components/library/LibraryCreateOverlay';
import {useStudioLocalization,studioLanguageOptions} from './workspace-i18n';
import {applyPrimaryColor} from './primary-color';
import {WorkspaceRenameDialog} from './components/layout/WorkspaceRenameDialog';
import {TimePreferences,browserTimeZone,validTimeZone} from './components/layout/TimePreferences';
import type {AssistantAction} from './assistant/contracts';
import {PersistentActionDock} from './components/library/PersistentActionDock';
import {AssistantToggle,useAssistantPreview} from './assistant/AssistantBoundary';
import {Tooltip} from '@heroui/react';
import {ArrowLeft01Icon,Search01Icon} from '@hugeicons/core-free-icons';
import {AssistantBoundary} from './assistant/AssistantBoundary';
import {StudioToasts, useInboxToasts} from './components/management/StudioToasts';
import {useBrowserPush} from './components/management/useBrowserPush';
import {ContextSidebar} from './components/layout/ContextSidebar';
import {StudioShell} from './components/layout/StudioShell';
import {resolveViewLayout} from './components/layout/layout-config';
import {WorkspaceCreateDialog} from './components/layout/WorkspaceCreateDialog';
import {useWorkspaceDirectory} from './components/layout/useWorkspaceDirectory';
import {IntegrationsPage} from './components/integrations/IntegrationsPage';
import {AiPage} from './components/ai/AiPage';
import type {AiConfiguration} from './contracts';
import type {LibraryScope} from './application-files';
import {ApplicationFilesPage} from './components/management/ApplicationFilesPage';
import {StudioHttpError} from './http';
import {fileCaption,fileNameParts} from './file-caption';
import {isRTL,Breadcrumbs, Button, Checkbox, Input, Label, Modal, Spinner, Select, ListBox, ToastQueue} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {Add01Icon, Folder01Icon, Image01Icon, Delete02Icon, FolderAddIcon, StarIcon, Bookmark01Icon, TickDouble02Icon, Download01Icon, Share08Icon, FolderMoveToIcon, Undo02Icon, Cancel01Icon, FileZipIcon} from '@hugeicons/core-free-icons';
import {useVirtualizer} from '@tanstack/react-virtual';
import {animate,motion,useMotionValue,useMotionValueEvent,useReducedMotion} from 'motion/react';
import {memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode} from 'react';
import type {BrowseQuery, BrowseFilters, BrowseSort, BrowseDirection, Entry, EntryPage, MediaReference, BulkEntry, BulkEntryRequest, StudioOptions, Workspace, WorkspaceSettings} from './contracts';
import {appendPage} from './window';
import {randomId} from './random-id';
import {useMarquee} from './components/selection/useMarquee';
import {SharesPage, TrashPage, TagsPage, NotificationsPage, ShareDialog} from './components/management';
import {NavRail, type NavigationSection} from './components/layout/NavRail';
import {FilePreview, type PreviewAnchor} from './components/library/FilePreview';
import {NoteComposer} from './components/notes/NoteComposer';
import folderArtwork from '../assets/folder.svg';
import {AudioArtwork} from './components/library/AudioArtwork';
import {FileTypeIcon} from './components/library/FileTypeIcon';
import {MoveDialog, DestinationDialog} from './components/library/MoveDialog';
import {useMoveDrag} from './components/selection/useMoveDrag';
import {EntryActions, type EntryActionHandlers} from './components/library/EntryActions';
import {type ToggleFavorite} from './components/library/FavoriteButton';
import {validateBrowseExtensions} from './browse-extensions';
import {FilterControls, SortControl} from './components/library/BrowseControls';
import {StudioSettings} from './components/layout/StudioSettings';
import {defaultStudioLocation, readStudioLocation, studioUrl, type StudioLocation} from './studio-location';
import {MembersPage} from './components/management/MembersPage';
import {WorkspaceMembers} from './components/library/WorkspaceMembers';
import {StudioHeader} from './components/layout/StudioHeader';
import {LayoutControl, LibraryCreationActions, type LibraryLayout} from './components/library/LibraryMenus';
import {createPortal,flushSync} from 'react-dom';
import {PhotosToolbar,type PhotoMedia,type PhotoPeriod,type PhotoAspect} from './components/photos/PhotosToolbar';
import {usePhotoZoom} from './components/photos/usePhotoZoom';
import {clampPhotoZoom,photoGridGeometry,photoGeometryForColumns,photoPinchLevel,type PhotoZoomAnchor} from './components/photos/photo-zoom';
import {timelineRows,type TimelineRow} from './components/photos/timeline';
import {PhotoCard} from './components/photos/PhotoCard';
import {BulkDialog} from './components/library/BulkDialog';
import {EntryInspector} from './components/library/EntryInspector';
import {TransferPanel} from './components/library/TransferPanel';
import {createTransferQueue,type TransferItem,type TransferQueue} from './uploads';

type Messages = import('./ui').Messages;
type AppProps = StudioOptions & {portalContainer: HTMLDivElement; onInitialReady: () => void};
const Icon = ({icon, size = 20}: {icon: typeof Folder01Icon; size?: number}) => <HugeiconsIcon icon={icon} size={size} strokeWidth={2}/>;
const MAX_BULK_ITEMS = 100;
const errorText = (error: unknown) => error instanceof Error ? error.message : 'The request could not be completed.';


export function StudioApp(props: AppProps) {
  const libraryScrollViewport=useRef<HTMLDivElement>(null);
  const {portalContainer, workspaceId} = props;
  const folderOptionsKey=JSON.stringify(props.layout?.folderTreeOptions??{});
  const folderNavigation=useMemo(()=>createFolderTreeTransport(props.transport, props.layout?.folderTreeOptions),[props.transport, folderOptionsKey]);
  const transport=folderNavigation.transport;
  useEffect(()=>()=>folderNavigation.destroy(),[folderNavigation]);
  const syncUrl = props.syncUrl === true && props.mode !== 'picker';
  const pickerRoot = props.mode==='picker' ? props.pickerScopedFolder ?? null : null;
  const [location, setLocation] = useState(() => syncUrl ? readStudioLocation(window.location.href) : {
    ...defaultStudioLocation(),
    folder: props.mode==='picker' ? pickerRoot ?? props.pickerInitialFolder ?? null : null,
  });
  const locationRef = useRef(location);
  const setRoute = useCallback((next: StudioLocation, replace = false) => {
    locationRef.current = next;
    setLocation(next);
    if (!syncUrl) return;
    const url = studioUrl(window.location.href, next);
    if (url.href !== window.location.href) window.history[replace ? 'replaceState' : 'pushState'](window.history.state, '', url);
  }, [syncUrl]);
  const preferenceKey = 'flex-media-studio.preferences.' + (props.user?.email ?? 'browser');
  const [preferences, setPreferences] = useState<{locale?: string; notifications?: boolean;autoTimezone?:boolean;timezone?:string;appearance?:'light'|'dark'|'system'}>(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(preferenceKey) ?? '{}');
      return {appearance:['light','dark','system'].includes(saved.appearance)?saved.appearance:undefined,locale: typeof saved.locale === 'string' ? saved.locale : undefined, notifications: saved.notifications === true,autoTimezone:saved.autoTimezone!==false,timezone:validTimeZone(saved.timezone)?saved.timezone:browserTimeZone()};
    } catch { return {}; }
  });
  const {locale:language,messages:t,requestLocale}=useStudioLocalization();
  const locale=language;
  const [searchOpen,setSearchOpen]=useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const shortcutStorageKey=preferenceKey+'.shortcuts.v1';
  const [shortcutOverrides,setShortcutOverrides]=useState<ShortcutBindings>(()=>{try{return readShortcutPreferences(localStorage.getItem(shortcutStorageKey));}catch{return {};}});
  const [shortcuts,setShortcuts]=useState<ShortcutRegistry|null>(null);
  const shortcutOptions=props.shortcuts===false?undefined:props.shortcuts;
  const changeShortcuts=(bindings:ShortcutBindings)=>{setShortcutOverrides(bindings);try{localStorage.setItem(shortcutStorageKey,JSON.stringify({version:1,bindings}));}catch{}};
  useEffect(()=>{
    const mac=/Mac|iPhone|iPad/.test(navigator.platform),registry=new ShortcutRegistry(portalContainer,mac);
    setShortcuts(registry);return()=>registry.destroy();
  },[portalContainer]);
  useEffect(()=>{
    try{setShortcutOverrides(readShortcutPreferences(localStorage.getItem(shortcutStorageKey)));}catch{setShortcutOverrides({});}
    const synchronize=(event:StorageEvent)=>{if(event.key===shortcutStorageKey)setShortcutOverrides(readShortcutPreferences(event.newValue));};
    window.addEventListener('storage',synchronize);return()=>window.removeEventListener('storage',synchronize);
  },[shortcutStorageKey]);
  const shortcutBindings=resolveShortcutBindings(shortcutOptions?.bindings,shortcutOptions?.customizable===false?{}:shortcutOverrides,shortcuts?.mac??false);
  useLayoutEffect(()=>{if(shortcuts){shortcuts.enabled=props.shortcuts!==false&&shortcutOptions?.enabled!==false;shortcuts.bindings=shortcutBindings;}});
  useEffect(()=>{if(!shortcuts)return;const dispose=[shortcuts.register('search',()=>setSearchOpen(true)),shortcuts.register('settings',()=>setSettingsOpen(true))];return()=>dispose.forEach(fn=>fn());},[shortcuts]);

  const [workspaceEditor,setWorkspaceEditor]=useState<Workspace|null>(null);
  const [policy, setPolicy] = useState<{workspace: string; value: WorkspaceSettings} | null>(null);
  const [toastQueue] = useState(() => new ToastQueue());
  useEffect(() => () => toastQueue.clear(), [toastQueue]);
  const notifyUpload = useRef<(item: TransferItem) => void>(() => {});
  notifyUpload.current = item => {
    if (preferences.notifications) {
      toastQueue.add({title: t.uploadCompleted, description: item.name, variant: 'success'}, {timeout: 4000});
    }
  };
  const savePreferences = (next: typeof preferences) => {
    setPreferences(next);
    try { localStorage.setItem(preferenceKey, JSON.stringify({...next,locale:language})); } catch {}
  };
  const [libraryActions, setLibraryActions] = useState<HTMLDivElement|null>(null);
  const [libraryTools, setLibraryTools] = useState<HTMLDivElement|null>(null);
  const [selectionSlot,setSelectionSlot] = useState<HTMLDivElement|null>(null);
  const [pickerPreviewLayer,setPickerPreviewLayer] = useState<HTMLDivElement|null>(null);
  const [pickerSelection,setPickerSelection] = useState<ReadonlyMap<string,MediaReference>>(()=>new Map((props.pickerInitialSelection??[]).slice(0,120).map(ref=>[ref.assetId,ref])));
  const pickerSelectionRef=useRef(pickerSelection);
  const pickerLimit=Math.min(120,Math.max(1,Math.floor(props.pickerSelectionLimit??1)||1));
  const updatePickerSelection=useCallback((next:ReadonlyMap<string,MediaReference>)=>{
    pickerSelectionRef.current=next;
    setPickerSelection(next);
    props.onPickerSelectionChange?.([...next.values()]);
  },[props.onPickerSelectionChange]);
  const clearPickerSelection=useCallback(()=>{
    updatePickerSelection(new Map());
    props.onSelectionReset?.();
  },[props.onSelectionReset,updatePickerSelection]);
  const [layout, setLayout] = useState<LibraryLayout>('grid');
  const photoMedia: PhotoMedia = location.media;
  const photoPeriod: PhotoPeriod = location.period;
  const [photoAspect,setPhotoAspect] = useState<PhotoAspect>('square');
  const [photoZoom,setPhotoZoom] = useState(180);
  const photoZoomHandler=useRef<((value:number)=>void)|null>(null);
  const [systemDark,setSystemDark]=useState(()=>matchMedia('(prefers-color-scheme: dark)').matches);
  useEffect(()=>{const query=matchMedia('(prefers-color-scheme: dark)'),changed=()=>setSystemDark(query.matches);query.addEventListener('change',changed);return()=>query.removeEventListener('change',changed);},[]);
  const appearance=preferences.appearance??props.theme??'light';
  const theme=appearance==='system'?(systemDark?'dark':'light'):appearance;
  const changeAppearance=(value:'light'|'dark'|'system')=>{savePreferences({...preferences,appearance:value});props.onThemeChange?.(value==='system'?(systemDark?'dark':'light'):value);};
  const changeTheme=(value:'light'|'dark')=>changeAppearance(value);
  const [section, setSection] = useState<NavigationSection>('library');
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const [workspaceDialog, setWorkspaceDialog] = useState(false);
  const [assistantRevision,setAssistantRevision]=useState(0);
  const assistantMutation=useCallback(()=>setAssistantRevision(v=>v+1),[]);
  const [attempt, setAttempt] = useState(0);
  const {workspaceIdentity,workspaces, setWorkspaces,multipleEnabled, appearance:serverAppearance,viewer,canCreate, loading, error} = useWorkspaceDirectory(props, location.workspace, attempt);
  const view = location.view;
  const management = transport.management;
  const managedView = view === 'integrations' || view === 'ai' || view === 'application' || view === 'members' || view === 'shares' || view === 'tags' || view === 'notifications' || (view === 'trash' && !!management?.trashReport);
  const [canApplicationFiles,setCanApplicationFiles] = useState(false);
  const [sourcesReady,setSourcesReady] = useState(false);
  const [aiReady,setAiReady] = useState(false);
  const [libraryReady,setLibraryReady] = useState(false);
  const markLibraryReady = useCallback(() => setLibraryReady(true), []);
  useEffect(() => {
    const controller = new AbortController();
    setCanApplicationFiles(false);setSourcesReady(false);
    if (props.mode !== 'picker' && transport.applicationFiles) {
      void transport.applicationFiles.sources(controller.signal).then(value => {if(!controller.signal.aborted)setCanApplicationFiles(value.enabled);}).catch(() => {}).finally(()=>{if(!controller.signal.aborted)setSourcesReady(true);});
    } else setSourcesReady(true);
    return () => controller.abort();
  }, [transport,props.mode]);
  const viewerProfile=props.user??{...(viewer.name?{name:viewer.name}:{}),...(viewer.email?{email:viewer.email}:{}),...(viewer.avatarUrl?{avatarUrl:viewer.avatarUrl}:{})};
  const primaryColor=props.primaryColor??serverAppearance.primaryColor??'#0866ff';
  useLayoutEffect(()=>{
    applyPrimaryColor(portalContainer,primaryColor);
  },[portalContainer,primaryColor]);
  const [aiInfo,setAiInfo] = useState<AiConfiguration|null>(null);
  const [aiError,setAiError] = useState('');
  const [aiAttempt,setAiAttempt] = useState(0);
  useEffect(()=>{
    const controller = new AbortController();
    setAiInfo(null);setAiError('');setAiReady(false);
    if(props.mode!=='picker'&&transport.aiInfo)void transport.aiInfo(controller.signal)
      .then(info=>{if(!controller.signal.aborted)setAiInfo(info);})
      .catch(error=>{if(!controller.signal.aborted)setAiError(errorText(error));})
      .finally(()=>{if(!controller.signal.aborted)setAiReady(true);});
    else setAiReady(true);
    return ()=>controller.abort();
  },[transport,props.mode,aiAttempt]);
  const [navigationRevision, setNavigationRevision] = useState(0);
  const [collapsedPath, setCollapsedPath] = useState(false);
  const [path, setPath] = useState<{id: string; name: string}[]>([]);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [filters,setFilters] = useState<BrowseFilters>({});
  const [customFilters,setCustomFilters] = useState<Record<string,string>>({});
  const browseExtensions = useMemo(()=>validateBrowseExtensions(props.browse),[props.browse]);
  const [sort,setSort] = useState<{key:BrowseSort;direction:BrowseDirection}>({key:'name',direction:'asc'});
  useEffect(() => {
    portalContainer.classList.toggle('dark', theme === 'dark');
    portalContainer.classList.toggle('light', theme !== 'dark');
    portalContainer.lang = language;
    portalContainer.dir=isRTL(language)?'rtl':'ltr';
  }, [portalContainer, theme, language]);
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 200);
    return () => clearTimeout(timer);
  }, [search]);
  const navigateSection = useCallback((next: NavigationSection) => {
    setSection(next); setSidebarOpen(true);
  }, []);
  const [queue,setQueue] = useState<TransferQueue|null>(null);
  const onSourceImportComplete = useRef<((workspace:string)=>void)|null>(null);
  const onTransferComplete = useRef<((item:TransferItem)=>void)|null>(null);
  const transfers = transport.transfers;
  const {enabled,concurrency,maxFiles,maxRetainedCompleted,progressIntervalMs,chunkThresholdBytes} = props.upload??{};
  useEffect(()=>{
    const current = enabled!==false&&transfers ? createTransferQueue({
      transport:transfers,
      ...(concurrency===undefined?{}:{concurrency}),
      ...(maxFiles===undefined?{}:{maxFiles}),
      ...(maxRetainedCompleted===undefined?{}:{maxRetainedCompleted}),
      ...(progressIntervalMs===undefined?{}:{progressIntervalMs}),
      ...(chunkThresholdBytes===undefined?{}:{chunkThresholdBytes}),
      onComplete:(_entry,item)=>{onTransferComplete.current?.(item); notifyUpload.current(item);},
    }) : null;
    setQueue(current);
    return ()=>current?.destroy();
  },[enabled,transfers,concurrency,maxFiles,maxRetainedCompleted,progressIntervalMs,chunkThresholdBytes]);
  const lastWorkspaceId=useRef<string|null>(null);
  const requestedWorkspace = workspaceId ?? location.workspace ?? (view==='application'?lastWorkspaceId.current:null);
  const active = requestedWorkspace ? workspaces.find(w => w.id === requestedWorkspace) : workspaces[0];
  const activeId = active?.id;
  const navigationClient = useMemo(() => activeId && transport.folders ? new FolderNavigationClient(activeId, transport.folders) : null, [activeId, transport]);
  useEffect(() => () => navigationClient?.cancel(), [navigationClient]);

  const folderTree=activeId?folderNavigation.tree(activeId):null;
  useEffect(()=>{if(assistantRevision)folderTree?.refresh();},[assistantRevision,folderTree]);
  const seenAssistantActions=useRef(new Set<string>());
  const assistantAction=useCallback((action:AssistantAction)=>{
    if(!activeId||!action.ok||!action.actionId)return;
    const key='fms.assistant.action.'+(props.user?.email??'session')+'.'+activeId;
    let seen=seenAssistantActions.current;
    try{const saved=JSON.parse(sessionStorage.getItem(key)??'[]') as string[];for(const id of saved)seen.add(id);}catch{}
    if(seen.has(action.actionId))return;
    seen.add(action.actionId);seenAssistantActions.current=new Set([...seen].slice(-256));
    try{sessionStorage.setItem(key,JSON.stringify([...seenAssistantActions.current]));}catch{}
    const e=action.entry;
    if(action.command==='open'&&e){setRoute({...locationRef.current,workspace:activeId,view:'files',...(e.kind==='folder'?{folder:e.id,file:null}:{file:e.id})});return;}
    if(action.command==='download'&&e){window.location.assign(transport.contentUrl(activeId,e.id,true));return;}
    assistantMutation();
    const labels:Record<string,[string,string]>={rename:['Zmieniono nazwę','Renamed'],move:['Przeniesiono','Moved'],favorite:['Zmieniono ulubione','Favorite updated'],tags:['Zapisano tagi','Tags saved'],comment:['Dodano komentarz','Comment added'],resolve_comment:['Zmieniono status komentarza','Comment status updated'],create_folder:['Utworzono folder','Folder created'],trash:['Przeniesiono do kosza','Moved to trash'],restore:['Przywrócono','Restored'],create_share:['Utworzono link','Share link created']};
    let undoing=false;
    toastQueue.add({title:labels[action.action]?.[locale==='pl'?0:1]??(locale==='pl'?'Wykonano akcję':'Action completed'),description:e?.name,variant:'success',...(action.undoable&&transport.assistant?.undo?{actionProps:{children:locale==='pl'?'Cofnij':'Undo',onPress:()=>{if(undoing)return;undoing=true;void transport.assistant!.undo!(activeId,action.actionId,new AbortController().signal).then(()=>{assistantMutation();toastQueue.add({title:locale==='pl'?'Cofnięto akcję':'Action undone',variant:'success'},{timeout:4000});}).catch(error=>{undoing=false;toastQueue.add({title:locale==='pl'?'Nie udało się cofnąć':'Could not undo',description:error instanceof Error?error.message:String(error),variant:'danger'},{timeout:7000});});}}}:{})},{timeout:12000});
  },[activeId,props.user?.email,setRoute,transport,assistantMutation,toastQueue,locale]);

  const browserPush = useBrowserPush(
    props.mode === 'picker' || active?.permissions?.['notifications.view'] === false ? undefined : management?.browserPush,
    activeId, settingsOpen || view === 'notifications', t, locale,
  );
  const inbox=useInboxToasts(
    props.mode === 'picker' || active?.permissions?.['notifications.view'] === false ? undefined : management?.notifications,
    activeId, locale, toastQueue,
  );
  if(view!=='application'&&activeId)lastWorkspaceId.current=activeId;
  const [folderError, setFolderError] = useState('');
  const [folderDetails, setFolderDetails] = useState<Entry | null>(null);
  const [folderStarPending, setFolderStarPending] = useState(false);
  const [titleCount, setTitleCount] = useState<HTMLSpanElement | null>(null);
  useEffect(() => {
    setFolderDetails(null);
    if (!activeId || !location.folder || view !== 'files') return;
    const controller = new AbortController();
    void transport.details(activeId, location.folder, controller.signal).then(folder => {
      if (!controller.signal.aborted && folder.kind === 'folder') setFolderDetails(folder);
    }).catch(() => {});
    return () => controller.abort();
  }, [activeId, location.folder, transport, view]);
  const knownPath = useRef<{workspace: string; items: typeof path} | null>(null);
  useEffect(() => {
    if (!syncUrl) return;
    const restore = () => {
      const next = readStudioLocation(window.location.href);
      const previous = locationRef.current;
      locationRef.current = next;
      setLocation(next);
      if (next.workspace !== previous.workspace || next.view !== previous.view || next.folder !== previous.folder) {
        setSearch('');
        setDebouncedSearch('');
      }
    };
    window.addEventListener('popstate', restore);
    return () => window.removeEventListener('popstate', restore);
  }, [syncUrl]);
  useEffect(() => {
    setFolderError('');
    if (!activeId || !location.folder) {
      knownPath.current = null;
      setCollapsedPath(false);
      setPath([]);
      return;
    }
    if (!navigationClient && knownPath.current?.workspace === activeId && knownPath.current.items.at(-1)?.id === location.folder) return;
    const controller = new AbortController();
    void (async () => {
      const items: typeof path = [];
      let collapsed = false;
      if (navigationClient) {
        const context = await navigationClient.open(location.folder!);
        items.push(...context.tail);
        collapsed = context.hasCollapsedAncestors;
      } else {
        // Legacy transports remain usable without walking an unbounded path.
        let id: string | null = location.folder;
        while (id && items.length < 4) {
          const folder = await transport.details(activeId, id, controller.signal);
          if (folder.kind !== 'folder' || folder.trashedAt || items.some(item => item.id === folder.id)) throw new Error(t.folderUnavailable);
          items.unshift({id: folder.id, name: folder.name});
          id = folder.parentId;
        }
        collapsed = id !== null;
      }
      if (!controller.signal.aborted) {
        knownPath.current = {workspace: activeId, items};
        setPath(items);
        setCollapsedPath(collapsed);
      }
    })().catch(error => {if (!controller.signal.aborted && error?.name !== 'AbortError') setFolderError(t.folderUnavailable);});
    return () => {controller.abort(); navigationClient?.cancel();};
  }, [activeId, location.folder, transport, navigationClient, t.folderUnavailable]);
  useEffect(() => {
    if (!activeId || !transport.settings) return;
    const controller = new AbortController();
    void transport.settings(activeId, controller.signal).then(value => {
      if (!controller.signal.aborted) setPolicy({workspace: activeId, value});
    }).catch(() => {});
    return () => controller.abort();
  }, [activeId, transport]);
  const changeFolder = (items: typeof path) => {
    if (!activeId) return;
    if (pickerRoot && (!items.length || location.folder === pickerRoot && items.at(-1)?.id !== pickerRoot && !items.some(item=>item.id===pickerRoot))) return;
    const bounded = items.slice(-4);
    knownPath.current = {workspace: activeId, items: bounded};
    setPath(bounded);
    setCollapsedPath(items.length>4 || items.length>0&&collapsedPath);
    setRoute({...location, workspace: activeId, view: 'files', folder: items.at(-1)?.id ?? null, file: null});
  };
  const onPreviewRoute = useCallback((file: string | null, replace = false) => {
    if (activeId && locationRef.current.file !== file) setRoute({...locationRef.current, workspace: activeId, file}, replace);
  }, [activeId, setRoute]);
  const previewLink = useCallback((entry: Entry) => studioUrl(window.location.href, {...locationRef.current, workspace: activeId ?? null, file: entry.id}).href, [activeId]);
  useLayoutEffect(() => {
    const resolvedPath=knownPath.current;
    const pathReady=!location.folder||resolvedPath!==null&&resolvedPath.workspace===activeId&&resolvedPath.items.at(-1)?.id===location.folder;
    if (!loading && sourcesReady && aiReady && (error || folderError || !active || managedView || libraryReady&&pathReady)) props.onInitialReady();
  }, [loading, error, folderError, active, activeId, location.folder, path, managedView, libraryReady, sourcesReady, aiReady, props.onInitialReady]);
  useEffect(() => {
    if (!activeId || !transport.folders?.changes) return;
    const sync = new NavigationSync(activeId, transport.folders, page => {
      folderTree?.applyChanges(page.items, page.reset);
      setNavigationRevision(value => value + 1);
      const folder = locationRef.current.folder;
      if (folder && navigationClient) void navigationClient.open(folder).then(context => {
        if (locationRef.current.folder !== folder) return;
        knownPath.current = {workspace: activeId, items: context.tail};
        setPath(context.tail);
        setCollapsedPath(context.hasCollapsedAncestors);
      }).catch(() => {});
    });
    const refresh = () => {if (!document.hidden) void sync.refresh();};
    sync.start();
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {sync.destroy(); window.removeEventListener('focus', refresh); document.removeEventListener('visibilitychange', refresh);};
  }, [activeId, transport, navigationClient, folderTree]);
  const browseControls = (view==='files'||view==='tagged')&&transport.supportsBrowseControls===true;
  const pickerMimeTypes = props.mode==='picker' ? props.pickerMimeTypes : null;
  const query = useMemo<BrowseQuery>(() => ({
    parent:view==='files'?location.folder:null,
    view: managedView ? 'files' : view,
    search:debouncedSearch,
    ...(props.mode==='picker'&&props.pickerThumbnailPreset?{thumbnailPreset:props.pickerThumbnailPreset}:{}),
    ...(view==='tagged' && location.tag ? {filters: {...filters,tagIds:[location.tag],...(pickerMimeTypes?{mimeTypes:pickerMimeTypes}:{})}} : {}),
    ...(view==='photos'?{photos:{kind:photoMedia,period:photoPeriod}}:{}),
    ...(view!=='tagged'&&(pickerMimeTypes||browseControls&&Object.keys(filters).length)?{filters:{...filters,...(pickerMimeTypes?{mimeTypes:pickerMimeTypes}:{})}}:{}),
    ...(browseControls&&Object.keys(customFilters).length?{customFilters}:{}),
    ...(browseControls&&(sort.key!=='name'||sort.direction!=='asc')?{sort:sort.key,direction:sort.direction}:{}),
  }), [browseControls,customFilters,debouncedSearch,filters,location.folder,location.tag,managedView,pickerMimeTypes,props.mode,props.pickerThumbnailPreset,sort,view,photoMedia,photoPeriod]);
  const navigateView = (value:typeof view)=>{
    setRoute({...location, workspace: activeId ?? null, view: value, folder: null, file: null});
    setPath([]);
    setSearch('');
    setSidebarOpen(false);
  };
  const navigateFolder = (entry: Entry) => {
    changeFolder([...path, {id: entry.id, name: entry.name}]);
    setSearch('');
  };
  const visits = useRef(new Set<AbortController>());
  useEffect(()=>()=>{
    for(const controller of visits.current)controller.abort();
  },[transport]);
  const recordVisit = useCallback((workspace:string,entry:string)=>{
    if(!transport.visit)return;
    const controller = new AbortController();
    visits.current.add(controller);
    void transport.visit(workspace,entry,controller.signal).catch(()=>{}).finally(()=>visits.current.delete(controller));
  },[transport]);
  const onApplicationPreview = useCallback((file:string|null,replace=false)=>setRoute({...locationRef.current,workspace:null,file},replace),[setRoute]);
  const viewLabel = view==='tags' ? t.tagDirectory : t[view];
  const title = view==='files'?path.at(-1)?.name??viewLabel:viewLabel;
  const isPicker = props.mode === 'picker';
  const pickerAccepts=useCallback((entry:Entry)=>!props.pickerMimeTypes||props.pickerMimeTypes.some(type=>type===entry.mimeType||(type.endsWith('/*')&&entry.mimeType?.startsWith(type.slice(0,-1)))),[props.pickerMimeTypes]);
  const selectPickerLoaded=useCallback((entries:Entry[])=>{
    if(!activeId)return;
    const next=new Map(pickerSelectionRef.current);
    const added:{reference:MediaReference;entry:Entry}[]=[];
    for(const entry of entries){
      if(next.size>=pickerLimit)break;
      if(entry.kind!=='file'||!entry.versionId||entry.trashedAt||next.has(entry.id)||!pickerAccepts(entry))continue;
      const reference={workspaceId:activeId,assetId:entry.id,versionId:entry.versionId};
      next.set(entry.id,reference);
      added.push({reference,entry});
    }
    if(added.length){
      updatePickerSelection(next);
      for(const {reference,entry} of added)props.onSelect?.(reference,entry);
    }
  },[activeId,pickerLimit,pickerAccepts,props.onSelect,updatePickerSelection]);
  const togglePickerSelection=useCallback((entry:Entry,checked:boolean)=>{
    if(!activeId||!entry.versionId||entry.kind!=='file'||entry.trashedAt||!pickerAccepts(entry))return false;
    const current=pickerSelectionRef.current;
    if(!checked){
      if(!current.has(entry.id))return false;
      const next=new Map(current);
      next.delete(entry.id);
      updatePickerSelection(next);
      if(!next.size)props.onSelectionReset?.();
      return true;
    }
    if(current.has(entry.id))return true;
    const multiple=props.pickerMultipleSelection??pickerLimit>1;
    if(multiple&&current.size>=pickerLimit)return false;
    const next=multiple?new Map(current):new Map<string,MediaReference>();
    const reference={workspaceId:activeId,assetId:entry.id,versionId:entry.versionId};
    next.set(entry.id,reference);
    updatePickerSelection(next);
    props.onSelect?.(reference,entry);
    return true;
  },[activeId,pickerLimit,pickerAccepts,props.pickerMultipleSelection,props.onSelect,props.onSelectionReset,updatePickerSelection]);
  const viewLayout = resolveViewLayout(view, props.layout, isPicker);
  const panelType = viewLayout.panel;
  const customPanel = panelType && typeof panelType === 'object' ? panelType : null;
  const panelSection = 'library';
  const hideSidebar = !panelType;
  const panel = customPanel ? customPanel.render({view, workspace: active ?? null, locale, close: () => setSidebarOpen(false), navigate: navigateView}) : panelType ?
    <ContextSidebar section={panelSection} view={view} folderTree={props.layout?.folderTree===false?null:folderTree} folderPath={path} collapsedPath={collapsedPath} selectedFolder={location.folder} preferenceKey={preferenceKey} onFolder={(folder:FolderSummary)=>{setSection('library');setSearch('');changeFolder(folderTree?.pathTo(folder)??[folder]);setSidebarOpen(false);}} canApplicationFiles={canApplicationFiles} transport={transport} management={management} workspaces={workspaces} activeId={activeId} canCreate={canCreate} locale={locale} portalContainer={portalContainer} t={t}
      onView={navigateView} onWorkspace={id=>{setRoute({...location, view:view==='application'?'files':view, workspace:id, folder:null, file:null});setPath([]);setSearch('');setSidebarOpen(false);}}
      onCreate={()=>{setWorkspaceDialog(true);setSidebarOpen(false);}} onSaved={updated=>setWorkspaces(current=>current.map(item=>item.id===updated.id?updated:updated.isDefault?{...item,isDefault:false}:item))}/> : null;
  return <ShortcutContext value={shortcuts}><TimePreferences zone={preferences.autoTimezone===false&&validTimeZone(preferences.timezone)?preferences.timezone:browserTimeZone()} locale={language}><AssistantBoundary userName={props.user?.name??(locale==='pl'?'Ty':'You')} onMutation={assistantMutation} onAction={assistantAction} configuration={aiInfo?.assistant} transport={transport.assistant} workspace={activeId??null} file={location.file} folder={location.folder} portal={portalContainer} locale={locale} identity={props.user?.email??'session'}><StudioShell libraryScrollRef={libraryScrollViewport} scrollLibrary={!managedView&&!!active&&view!=='photos'} pickerPreviewLayer={isPicker&&<div className="fms-picker-preview-layer" ref={setPickerPreviewLayer}/>} preferenceKey={preferenceKey} picker={isPicker} panel={panel} panelKey={customPanel?.id ?? panelSection} panelLabel={customPanel?.label ?? (panelSection==='library'?t.library:t.workspaces)} panelWidth={viewLayout.panelWidth} open={sidebarOpen} onOpenChange={setSidebarOpen} closeLabel={t.closeNavigation} openLabel={t.openNavigation}
    rail={viewLayout.rail&&<NavRail section={section} items={props.layout?.navigation===false?undefined:props.layout?.navigation?.items} onNavigate={next=>{if(hideSidebar||view==='application')navigateView('files');navigateSection(next);}} view={view}
      canIntegrations={!!transport.integrations&&!!active&&active.permissions?.['integrations.view']!==false} canAi={!!aiInfo} canSettings={!active||active.permissions?.['settings.view']!==false||active.permissions?.['notifications.view']!==false} canMembers={!!active&&active.permissions?.['members.view']===true} onView={next=>{setSection('library');navigateView(next);}} onSettings={()=>setSettingsOpen(true)} canShare={!!management?.shares&&!!active&&active.permissions?.['shares.view']!==false} unreadCount={inbox.unread} canNotify={!!management?.notifications&&!!active&&active.permissions?.['notifications.view']!==false} labels={t} portal={portalContainer}
      logoUrl={props.branding?.logoUrl??new URL('./studio-mark.svg',import.meta.url).href} logoLabel={props.branding?.logoLabel??'FLEX Media Studio'}/>}
    header={navigation=>viewLayout.header&&
      <StudioHeader onAccountSecurity={props.onAccountSecurity} workspaces={workspaces} activeWorkspace={active} multiWorkspace={multipleEnabled} canCreateWorkspace={canCreate} onWorkspace={id=>{setRoute({...location,workspace:id,view:'files',folder:null,file:null});setPath([]);setSearch('');}} onCreateWorkspace={()=>setWorkspaceDialog(true)} onManageWorkspace={()=>setWorkspaceEditor(active??null)} onSettings={()=>setSettingsOpen(true)} onTrash={()=>navigateView('trash')} onLogout={props.onLogout} workspaceSelector={isPicker&&active&&workspaces.length>1&&<div className="fms-picker-workspaces"><Select variant="secondary" aria-label={t.workspaces} value={active.id} onChange={key=>{if(typeof key!=='string'||!workspaces.some(w=>w.id===key))return;clearPickerSelection();setRoute({...location,workspace:key,folder:null,file:null});setPath([]);setSearch('');setFilters({});}}><Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger><Select.Popover UNSTABLE_portalContainer={portalContainer}><ListBox>{workspaces.map(w=><ListBox.Item key={w.id} id={w.id} textValue={w.name}><Label>{w.name}</Label><ListBox.ItemIndicator/></ListBox.Item>)}</ListBox></Select.Popover></Select></div>} picker={isPicker} search={search} onSearch={setSearch} user={viewerProfile} appearance={appearance} onAppearance={changeAppearance} portal={portalContainer} t={t}
        breadcrumbs={!isPicker&&<FolderBreadcrumbs path={view==='files'?path:[]} collapsed={view==='files'&&collapsedPath} client={navigationClient} portal={portalContainer} t={t} rootLabel={viewLabel} {...(view==='files'&&location.folder&&path.at(-1)?.id===location.folder?{onBack:()=>changeFolder(path.slice(0,-1))}:{})} onRoot={()=>navigateView(view==='files'?'files':view)} onFolder={folder=>{const index=path.findIndex(item=>item.id===folder.id);changeFolder(index>=0?path.slice(0,index+1):[folder]);}}/>}
        navigation={navigation}/>}
    overlays={<>
    {!isPicker&&(active&&transport.librarySearch?(
          <LibrarySearch spotlight isOpen={searchOpen} onOpenChange={setSearchOpen} key={active.id} workspace={active} workspaces={workspaces} multiple={multipleEnabled} indexed={active.indexedSearch??aiInfo?.search?.enabled===true} semantic={active.semanticSearch??aiInfo?.features.semanticSearch.enabled===true}
            transport={transport} search={transport.librarySearch} extensions={browseExtensions} portal={portalContainer} t={t} onOpen={(workspace,entry)=>{
              setSearchOpen(false);
              setSection('library');setSearch('');setFilters({});setCustomFilters({});setPath([]);
              if(entry.kind==='file')recordVisit(workspace,entry.id);
              setRoute({...location,view:'files',workspace,folder:entry.kind==='folder'?entry.id:null,file:entry.kind==='file'?entry.id:null,tag:null});
            }}/>
    ):<BasicSearchSpotlight open={searchOpen} onOpenChange={setSearchOpen} value={search} onChange={setSearch} portal={portalContainer} t={t}/>)}
    {queue&&props.upload?.showPanel!==false&&<TransferPanel queue={queue} t={t} initiallyExpanded={props.upload?.panelInitiallyExpanded??true}/>}
    <StudioToasts queue={toastQueue} portal={portalContainer} label={t.notifications}/>
    {workspaceEditor&&<WorkspaceRenameDialog workspaces={workspaces} workspace={workspaceEditor} transport={transport} portal={portalContainer} multiple={multipleEnabled} onDeleted={target=>{const remaining=workspaces.filter(w=>w.id!==workspaceEditor.id);setWorkspaces(remaining);setRoute({...location,workspace:remaining.find(w=>w.id===target)?.id??remaining[0]?.id??null,folder:null,file:null,view:'files'});}} onSaved={value=>setWorkspaces(items=>items.map(w=>w.id===value.id?value:value.isDefault?{...w,isDefault:false}:w))} onClose={()=>setWorkspaceEditor(null)}/>}
    {settingsOpen && <StudioSettings shortcuts={props.shortcuts===false?null:{registry:shortcuts,bindings:shortcutBindings,defaults:shortcutOptions?.bindings??{},overrides:shortcutOverrides,customizable:shortcutOptions?.customizable!==false,onChange:changeShortcuts,t}} canViewWorkspaceSettings={active?.permissions?.['settings.view']!==false} onSourceImported={workspace=>onSourceImportComplete.current?.(workspace)} canSources={active?.permissions?.['sources.sync']===true&&(active.role==='owner'||active.role==='admin')} workspace={activeId} transport={transport} portal={portalContainer} t={t} locale={language} languages={studioLanguageOptions(language,props.localization)} theme={theme} onTheme={changeTheme}
      autoTimezone={preferences.autoTimezone!==false} timezone={preferences.timezone??browserTimeZone()} onTimezone={(autoTimezone,timezone)=>savePreferences({...preferences,autoTimezone,timezone})} browserPush={browserPush} notifications={preferences.notifications === true} onLocale={requestLocale}
      onNotifications={value => savePreferences({...preferences, notifications: value})}
      onSaved={(workspace, value) => {setPolicy({workspace, value}); queue?.invalidatePolicy(workspace);}} onClose={() => setSettingsOpen(false)}/>}
    {workspaceDialog&&<WorkspaceCreateDialog directoryWorkspace={active?.id} identityEnabled={workspaceIdentity} portal={portalContainer} transport={transport} onClose={()=>setWorkspaceDialog(false)} onCreated={w=>{setWorkspaces(list=>list.some(item=>item.id===w.id)?list:[...list,w]);setRoute({...location,workspace:w.id,folder:null,file:null,view:'files'});setPath([]);setSearch('');}}/>}
    </>}>
      {view==='integrations'&&!isPicker ? <IntegrationsPage key={activeId??'none'} workspace={activeId} workspaces={workspaces} transport={transport} locale={locale} portal={portalContainer} search={debouncedSearch} detail={location.integration??null} onDetail={integration=>setRoute({...location,integration,file:null})} onAi={aiTab=>setRoute({...location,view:'ai',aiTab,file:null,folder:null})} onWorkspace={workspace=>setRoute({...location,workspace,file:null,folder:null})}/> : view==='ai'&&!isPicker ? <AiPage initialSection={location.aiTab??'connection'} workspace={activeId} transport={transport} onOpen={id=>setRoute({...location,view:'files',workspace:activeId??null,folder:null,file:id})} info={aiInfo} error={transport.aiInfo?aiError:(locale==='pl'?'Połączenia AI nie są dostępne w tej integracji.':'AI connections are not available in this integration.')} onRetry={()=>setAiAttempt(n=>n+1)} locale={locale} portal={portalContainer}/> : view==='application'&&!isPicker&&transport.applicationFiles ? <ApplicationFilesPage portal={portalContainer} transport={transport.applicationFiles} locale={locale} sourceId={location.source??null} folderId={location.applicationFolder??null} search={debouncedSearch} onNavigate={(source,applicationFolder)=>setRoute({...location,view:'application',workspace:null,folder:null,file:null,source,applicationFolder})}
        renderLibrary={parameters=><Library onSearch={()=>setSearchOpen(true)} key={JSON.stringify([location.source??null,parameters.scope.id,parameters.query])} {...props} user={viewerProfile} onInitialReady={markLibraryReady} locale={locale} transport={parameters.transport} scope={parameters.scope} query={parameters.query}
          routeFile={location.file} onPreviewRoute={onApplicationPreview} previewLink={undefined} accept={undefined} queue={null} comments={false}
          onSourceImportComplete={onSourceImportComplete} onTransferComplete={onTransferComplete} onNavigate={parameters.onNavigate} recordVisit={()=>{}} t={t}
          photoZoomHandler={photoZoomHandler} photoAspect={photoAspect} photoZoom={photoZoom} onPhotoZoom={setPhotoZoom} layout={layout} onLayoutChange={setLayout}
          actionSlot={null} toolSlot={parameters.toolSlot} titleCountSlot={parameters.titleCountSlot} sorter={null}/>}/> : loading ? <div className="fms-centered" role="status"><Spinner/><span>{t.loading}</span></div> : error ? <div className="fms-centered" role="alert"><p>{error}</p><Button onPress={() => setAttempt(n => n + 1)}>{t.retry}</Button></div> : !active ? <div className="fms-centered"><Icon icon={Folder01Icon} size={56}/><h1>{t.choose}</h1>{canCreate && <Button onPress={() => setWorkspaceDialog(true)}>{t.newWorkspace}</Button>}</div> : <>

        {managedView && management ? <>
          {view==='members'&&<MembersPage workspace={active} transport={transport} portal={portalContainer} locale={locale}/>}
          {view==='shares'&&<SharesPage workspace={active.id} transport={management} portal={portalContainer} locale={locale} guestUploads={active.guestUploads===true}/>}
          {view==='trash'&&<TrashPage workspace={active.id} transport={management} portal={portalContainer} locale={locale}/>}
          {view==='notifications'&&<NotificationsPage canMarkRead={active.permissions?.['notifications.manage']!==false} onUnread={inbox.setUnread} browserPush={browserPush} t={t} workspace={active.id} transport={management} locale={locale} onOpen={id=>setRoute({...location,workspace:active.id,view:'files',folder:null,file:id})}/>}
          {view==='tags'&&<TagsPage workspace={active.id} transport={management} locale={locale} onOpenTag={tag=>{setFilters({});setRoute({...location,workspace:active.id,view:'tagged',tag:tag.id,folder:null,file:null});}}/>}
        </> : <>
        <div className={view!=='photos'?'fms-library-viewport':'fms-library-viewport-pass'}>
        <div className="fms-library-header">
          <div className="fms-title-row"><div><div className="fms-title-heading">{isPicker&&location.folder&&location.folder!==pickerRoot&&path.at(-1)?.id===location.folder&&<Button isIconOnly variant="ghost" size="sm" aria-label={t.back} onPress={()=>changeFolder(path.slice(0,-1))}><Icon icon={ArrowLeft01Icon} size={17}/></Button>}<h1>{title}</h1><span ref={setTitleCount} className="fms-title-count"/><span className="fms-folder-favorite-slot">{folderDetails?.id===location.folder && transport.star && <Button isIconOnly size="sm" variant="ghost" aria-label={folderDetails.starred ? t.unfavorite : t.favorite} aria-pressed={folderDetails.starred === true} isDisabled={folderStarPending} onPress={() => {
            const folder = folderDetails; const controller = new AbortController(); setFolderStarPending(true);
            void transport.star!(active.id, folder.id, !folder.starred, controller.signal).then(({starred}) => setFolderDetails(current => current?.id === folder.id ? {...current, starred} : current)).catch(() => {}).finally(() => setFolderStarPending(false));
          }}><Icon icon={Bookmark01Icon} size={19}/></Button>}</span></div></div><div className="fms-title-right"><span className="fms-title-date-slot">{!isPicker&&(folderDetails?.updatedAt ?? (!location.folder ? active.updatedAt : null)) && (() => {const updated = folderDetails?.updatedAt ?? active.updatedAt!; return Number.isFinite(Date.parse(updated)) ? <time className="fms-title-time" dateTime={updated}>{new Intl.DateTimeFormat(locale,{dateStyle:"medium"}).format(new Date(updated))}</time> : null;})()}</span>{!isPicker&&active.permissions?.['members.view']!==false&&<WorkspaceMembers workspace={active} transport={transport} portal={portalContainer} locale={locale}/>}<div className="fms-library-actions" ref={setLibraryActions}/></div></div>
          {view==='photos'?<PhotosToolbar media={photoMedia} onMediaChange={media => setRoute({...location, workspace: active.id, media, file: null})} period={photoPeriod} onPeriodChange={period => setRoute({...location, workspace: active.id, period, file: null})} aspect={photoAspect} onAspectChange={setPhotoAspect} zoom={photoZoom} onZoomChange={value=>photoZoomHandler.current?.(value)} portal={portalContainer} t={t}/>:<div className="fms-library-sticky"><div className="fms-toolbar"><div className="fms-toolbar-filters">
            {browseControls&&<FilterControls uploaders={!!transport.members&&active.permissions?.['members.view']===true} filters={filters} customFilters={customFilters} extensions={browseExtensions} transport={transport} workspace={active.id} portal={portalContainer} t={t} apply={(builtIn,custom)=>{setFilters(builtIn);setCustomFilters(custom);}}/>}
          </div><div className="fms-toolbar-view" ref={setLibraryTools}/></div><div ref={setSelectionSlot}/></div>}
        </div>
        {folderError ? <div className="fms-centered" role="alert"><p>{folderError}</p><Button onPress={() => navigateView('files')}>{t.files}</Button></div> : <Library scrollViewport={view!=='photos'?libraryScrollViewport:undefined} onSearch={()=>setSearchOpen(true)} externalRevision={navigationRevision} toastQueue={toastQueue} key={JSON.stringify([active.id,query.view,query.search,query.photos,assistantRevision])} {...props} theme={theme} transport={transport} user={viewerProfile} onInitialReady={markLibraryReady} locale={locale} scope={active} query={query} folderPath={path.map(folder=>folder.name)} routeFile={location.file} onPreviewRoute={onPreviewRoute} previewLink={syncUrl ? previewLink : undefined} accept={pickerMimeTypes?.join(',') ?? (policy?.workspace === active.id ? policy.value.extensions.map(extension => '.' + extension).join(',') : undefined)} queue={queue} onSourceImportComplete={onSourceImportComplete} onTransferComplete={onTransferComplete} onNavigate={navigateFolder} recordVisit={recordVisit} t={t}
          photoZoomHandler={photoZoomHandler} photoAspect={photoAspect} photoZoom={photoZoom} onPhotoZoom={setPhotoZoom} layout={layout} onLayoutChange={setLayout} actionSlot={libraryActions} toolSlot={libraryTools} titleCountSlot={titleCount} selectionSlot={view!=='photos'?selectionSlot:null} pickerPreviewLayer={pickerPreviewLayer} pickerChoice={isPicker?{selection:pickerSelection, limit:pickerLimit, multiple:props.pickerMultipleSelection??pickerLimit>1, enabled:!!(props.onPickerSelectionChange||props.onSelect), toggle:togglePickerSelection, selectLoaded:selectPickerLoaded, clear:clearPickerSelection}:undefined}
          sorter={browseControls?<SortControl extensions={browseExtensions} sort={sort.key} direction={sort.direction} portal={portalContainer} t={t} order={(key,direction)=>setSort({key,direction})}/>:null}/>}
        </div>
        </>}
      </>}
</StudioShell></AssistantBoundary></TimePreferences></ShortcutContext>;
}

type PickerChoice = {selection:ReadonlyMap<string,MediaReference>; limit:number; multiple:boolean; enabled:boolean; toggle:(entry:Entry,checked:boolean)=>boolean; selectLoaded:(entries:Entry[])=>void; clear:()=>void};

function Library(props: AppProps & {selectionSlot?:HTMLDivElement|null;pickerPreviewLayer?:HTMLDivElement|null;pickerChoice?:PickerChoice|undefined;scrollViewport?:React.RefObject<HTMLDivElement|null>|undefined;onSearch?:()=>void;externalRevision?: number;toastQueue?: ToastQueue;folderPath?:readonly string[]; scope: LibraryScope; query: BrowseQuery; routeFile: string | null; onPreviewRoute: (file: string | null, replace?: boolean) => void; previewLink: ((entry: Entry) => string) | undefined; accept: string | undefined; queue:TransferQueue|null; onSourceImportComplete:React.RefObject<((workspace:string)=>void)|null>; onTransferComplete:React.RefObject<((item:TransferItem)=>void)|null>; onNavigate: (entry: Entry) => void; recordVisit:(workspace:string,entry:string)=>void; t: Messages; photoZoomHandler:React.RefObject<((value:number)=>void)|null>; photoAspect:PhotoAspect; photoZoom:number; onPhotoZoom:(value:number)=>void; actionSlot: HTMLDivElement|null; toolSlot: HTMLDivElement|null; titleCountSlot: HTMLSpanElement|null; sorter: ReactNode; layout: LibraryLayout; onLayoutChange: (layout: LibraryLayout) => void}) {
  const {transport, scope:workspace, query, t, portalContainer,queue,onTransferComplete,routeFile,onPreviewRoute} = props;
  const shortcuts=useShortcutRegistry();
  const ownScroll = useRef<HTMLDivElement>(null);
  const scroll=props.scrollViewport??ownScroll;
  const contentStart=useRef<HTMLDivElement>(null);
  const [scrollMargin,setScrollMargin]=useState(0);
  useLayoutEffect(()=>{
    const viewport=props.scrollViewport?.current,content=contentStart.current;
    if(!viewport||!content){setScrollMargin(0);return;}
    const measure=()=>setScrollMargin(Math.max(0,content.getBoundingClientRect().top-viewport.getBoundingClientRect().top+viewport.scrollTop));
    measure();const observer=new ResizeObserver(measure);observer.observe(viewport);
    observer.observe(content);
    viewport.querySelectorAll('.fms-title-row,.fms-library-sticky').forEach(node=>observer.observe(node));
    const changes=new MutationObserver(measure);changes.observe(viewport,{childList:true});
    return()=>{observer.disconnect();changes.disconnect();};
  },[props.scrollViewport]);
  const input = useRef<HTMLInputElement>(null);
  const folderInput=useRef<HTMLInputElement>(null);
  const {layout, onLayoutChange: setLayout} = props;
  const [inspectorOverlays,setInspectorOverlays]=useState<HTMLDivElement|null>(null);
  const [inspect,setInspect]=useState<Entry|null>(null),[move,setMove]=useState<Entry|null>(null),[trash,setTrash]=useState<Entry|null>(null),[mutating,setMutating]=useState(false);
  const controllers = useRef(new Set<AbortController>());
  const busy = useRef(false);
  const browseRequest=useRef<AbortController|null>(null);
  const anchor = useRef<{id:string;offset:number;y:number}|null>(null);
  const isPhotos=query.view==='photos';
  const [photoColumnCount,setPhotoColumnCount]=useState<number|null>(null);
  const densityScale=useMotionValue(1);
  const reducedMotion=useReducedMotion();
  const pinch=useRef<{log:number;point:PhotoZoomAnchor}|null>(null);
  const pendingScaleReset=useRef(false);
  const [width, setWidth] = useState(900);
  const [measured, setMeasured] = useState(false);
  const [pages, setPages] = useState<EntryPage[]>([]);
  const [pendingMoves, setPendingMoves] = useState<ReadonlySet<string>>(new Set());
  const queryKey=JSON.stringify(query);
  const [loadedQuery,setLoadedQuery]=useState<string|null>(null);
  const navigationPending=loadedQuery!==queryKey;
  const previousParent=useRef(query.parent);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [folderDialog, setFolderDialog] = useState(false);
  const [noteDialog,setNoteDialog]=useState<{entry:Entry|null}|null>(null);
  const [optimization, setOptimization] = useState<{entry: Entry; prune: boolean} | null>(null);
  const [optimizing, setOptimizing] = useState(false);
  const [optimizationError, setOptimizationError] = useState('');
  const [preview, setPreview] = useState<PreviewAnchor | null>(null);
  const [uploadState, setUploadState] = useState('');
  const [uploading, setUploading] = useState(false);
  const [selection,setSelection] = useState<ReadonlyMap<string,BulkEntry>>(()=>new Map());
  const moveDropBar = useRef<HTMLDivElement>(null);
  const [dragMoveItems, setDragMoveItems] = useState<BulkEntry[] | null>(null);
  const moveRequest = useRef<AbortController | null>(null);
  const [archiveJobs,setArchiveJobs]=useState<{id:string;state:string;expires_at:string}[]>([]);
  const [archiveRefresh,setArchiveRefresh]=useState(0);
  useEffect(()=>{
    if(!workspace.backgroundArchives||!transport.archiveJobs)return;
    const controller=new AbortController();
    let timer:ReturnType<typeof setTimeout>|undefined;
    const refresh=async()=>{try{const result=await transport.archiveJobs!(workspace.id,controller.signal);if(controller.signal.aborted)return;setArchiveJobs(result.items);if(result.items.some(item=>item.state==='queued'||item.state==='processing'))timer=setTimeout(refresh,5000);}catch(error){if(!controller.signal.aborted)setError(errorText(error));}};
    void refresh();
    return ()=>{controller.abort();if(timer)clearTimeout(timer);};
  },[workspace.id,workspace.backgroundArchives,transport,archiveRefresh]);
  const [shareEntries,setShareEntries] = useState<Entry[]|null>(null);
  const [bulkOperation,setBulkOperation] = useState<BulkEntryRequest['operation']|null>(null);
  const [bulkRefresh,setBulkRefresh] = useState(false);
  useEffect(() => {if (props.externalRevision) setBulkRefresh(true);}, [props.externalRevision]);
  const hiddenUntilRefresh = useRef(new Set<string>());
  const libraryRevision = useRef(0);
  const [pendingFavorites,setPendingFavorites] = useState<ReadonlySet<string>>(()=>new Set());
  const favoriteRequests = useRef(new Set<string>());
  const favoriteOverrides = useRef(new Map<string,boolean>());
  const starredRefresh = useRef(false);
  const previewActive = useRef(false);
  const displayedFile = useRef<string | null>(null);
  useEffect(() => {
    if (displayedFile.current === routeFile) return;
    if (!routeFile) {
      displayedFile.current = null;
      previewActive.current = false;
      setPreview(null);
      return;
    }
    const controller = new AbortController();
    void transport.details(workspace.id, routeFile, controller.signal).then(entry => {
      if (controller.signal.aborted) return;
      if (entry.kind !== 'file' || entry.trashedAt) throw new Error(t.previewUnavailable);
      displayedFile.current = entry.id;
      previewActive.current = true;
      if(entry.mediaType==='note'){setNoteDialog({entry});return;}
      setPreview({entry, rect: null, source: null});
    }).catch(() => {
      if (controller.signal.aborted) return;
      displayedFile.current = null;
      previewActive.current = false;
      setPreview(null);
      setError(t.previewUnavailable);
      onPreviewRoute(null, true);
    });
    return () => controller.abort();
  }, [onPreviewRoute, routeFile, t.previewUnavailable, transport, workspace.id]);
  const photoGeometry = photoColumnCount===null?photoGridGeometry(width,props.photoZoom):photoGeometryForColumns(width,Math.min(photoColumnCount,photoGridGeometry(width,64).columns));
  const columns = isPhotos?photoGeometry.columns:layout==='list'?1:Math.max(1, Math.min(6, Math.floor((width - 32) / (layout==='large-grid'?280:190))));
  const rowHeight=isPhotos?photoGeometry.row:layout==='list'?64:layout==='large-grid'?310:238;
  const rowPaddingStart=isPhotos?0:8;
  const items = useMemo(() => {
    const seen = new Set<string>();
    return pages.flatMap(p => p.items).filter(item => {if (seen.has(item.id) || pendingMoves.has(item.id)) return false; seen.add(item.id); return true;});
  }, [pages, pendingMoves]);
  const rowEntries=useMemo<TimelineRow[]>(()=>isPhotos?timelineRows(items,columns,photoGeometry.cell,query.photos?.period??'days',props.locale):Array.from({length:Math.ceil(items.length/columns)},(_,index)=>({key:items[index*columns]!.id,entries:items.slice(index*columns,(index+1)*columns),height:rowHeight,start:rowPaddingStart+index*rowHeight})),[items,columns,rowHeight,rowPaddingStart,isPhotos,photoGeometry.cell,query.photos?.period,props.locale]);
  const rows=rowEntries.length;
  const virtualizer = useVirtualizer({count:rows,getScrollElement:()=>scroll.current,estimateSize:index=>rowEntries[index]?.height??rowHeight,paddingStart:rowPaddingStart,scrollMargin,overscan:isPhotos?5:2,getItemKey:index=>rowEntries[index]?.key??index});
  const current = useRef({items,columns,rowHeight,rowPaddingStart,width,rowEntries,scrollMargin});
  current.current = {items,columns,rowHeight,rowPaddingStart,width,rowEntries,scrollMargin};
  const rememberAnchor = useCallback((point?:PhotoZoomAnchor) => {
    const {columns,rowHeight,rowPaddingStart,rowEntries,scrollMargin}=current.current;
    const y=point?.y??0;
    const top=(scroll.current?.scrollTop??0)+y-scrollMargin;
    let row=rowEntries.findIndex(value=>value.start+value.height>top);
    if(row<0)row=rowEntries.length-1;
    while(row<rowEntries.length&&rowEntries[row]?.entries.length===0)row++;
    const group=rowEntries[row];
    if(!group)return;
    const column=point?Math.min(columns-1,Math.max(0,Math.floor(point.x/(current.current.width/columns)))):0;
    const first=group.entries[Math.min(column,group.entries.length-1)];
    if(props.scrollViewport&&first&&top<group.start){anchor.current={id:first.id,offset:0,y:y+group.start-top};return;}
    const offset=top-group.start;
    const offsetHeight=offset<0&&rowPaddingStart?rowPaddingStart:(group.height||rowHeight);
    if(first)anchor.current={id:first.id,offset:Math.max(-1,Math.min(1,offset/offsetHeight)),y};
  },[]);
  useMotionValueEvent(densityScale,'change',value=>{
    const stage=scroll.current?.querySelector<HTMLElement>('.fms-photo-virtual');
    if(stage)stage.style.transform=value===1?'':`scale(${value})`;
  });
  const anchorPhoto=useCallback((point:PhotoZoomAnchor)=>{
    const viewport=scroll.current;if(!viewport)return;
    const view=viewport.getBoundingClientRect(),x=view.x+point.x,y=view.y+point.y;
    let nearest:{id:string;box:DOMRect;distance:number}|null=null;
    for(const element of viewport.querySelectorAll<HTMLElement>('[data-photo-id]')){
      const box=element.getBoundingClientRect();
      const dx=Math.max(box.left-x,0,x-box.right),dy=Math.max(box.top-y,0,y-box.bottom);
      const distance=dx*dx+dy*dy;
      if(!nearest||distance<nearest.distance)nearest={id:element.dataset.photoId!,box,distance};
    }
    if(nearest)anchor.current={id:nearest.id,offset:Math.max(0,Math.min(1,(y-nearest.box.top)/nearest.box.height)),y:point.y};
  },[]);
  const selectPhotoColumns=useCallback((count:number,point?:PhotoZoomAnchor)=>{
    if(count===current.current.columns)return;
    const viewport=scroll.current;
    if(!viewport)return;
    anchorPhoto(point??{x:viewport.clientWidth/2,y:viewport.scrollTop<1?48:viewport.clientHeight/2});
    pendingScaleReset.current=true;
    flushSync(()=>setPhotoColumnCount(count));
    props.onPhotoZoom(clampPhotoZoom(photoGeometryForColumns(current.current.width,count).cell));
  },[anchorPhoto,props.onPhotoZoom]);
  const startPhotoPinch=useCallback((point:PhotoZoomAnchor)=>{
    densityScale.stop();
    pinch.current={log:Math.log(densityScale.get()),point};
    const viewport=scroll.current,stage=viewport?.querySelector<HTMLElement>('.fms-photo-virtual');
    if(viewport&&stage&&densityScale.get()===1)stage.style.transformOrigin=`${point.x}px ${viewport.scrollTop+point.y}px`;
  },[densityScale]);
  const advancePhotoPinch=useCallback((delta:number,point:PhotoZoomAnchor)=>{
    const gesture=pinch.current;if(!gesture)return;
    gesture.log+=delta;gesture.point=point;
    const state=photoPinchLevel(current.current.width,current.current.columns,gesture.log);
    if(state.columns!==current.current.columns){
      gesture.log=0;
      selectPhotoColumns(state.columns,point);
      const viewport=scroll.current,stage=viewport?.querySelector<HTMLElement>('.fms-photo-virtual');
      if(viewport&&stage)stage.style.transformOrigin=`${point.x}px ${viewport.scrollTop+point.y}px`;
    }else if(!reducedMotion)densityScale.set(state.scale);
  },[densityScale,reducedMotion,selectPhotoColumns]);
  const endPhotoPinch=useCallback(()=>{
    const gesture=pinch.current;if(!gesture)return;
    pinch.current=null;
    const state=photoPinchLevel(current.current.width,current.current.columns,gesture.log,true);
    if(state.columns!==current.current.columns)selectPhotoColumns(state.columns,gesture.point);
    else if(reducedMotion)densityScale.jump(1);
    else animate(densityScale,1,{type:'spring',stiffness:540,damping:42,restDelta:.0005,restSpeed:.005});
  },[densityScale,reducedMotion,selectPhotoColumns]);
  const photoControls=usePhotoZoom({viewportRef:scroll,onBegin:startPhotoPinch,onPinch:advancePhotoPinch,onEnd:endPhotoPinch,onSelect:value=>selectPhotoColumns(photoGridGeometry(current.current.width,value).columns),enabled:isPhotos});
  useLayoutEffect(()=>{
    if(pendingScaleReset.current){pendingScaleReset.current=false;densityScale.jump(1);}
  },[columns,densityScale]);
  useEffect(()=>()=>densityScale.stop(),[densityScale]);
  useLayoutEffect(()=>{
    props.photoZoomHandler.current=photoControls.setZoom;
    return()=>{props.photoZoomHandler.current=null;};
  },[props.photoZoomHandler,photoControls.setZoom]);
  const load = useCallback(async (direction: 'next' | 'previous', cursor: string | null, replace = false) => {
    if (busy.current) return;
    busy.current = true;
    const controller = new AbortController(); controllers.current.add(controller);browseRequest.current=controller;
    setLoading(true); setError('');
    const revision = libraryRevision.current;
    try {
      const page = await transport.browse(workspace.id, query, cursor, controller.signal);
      if (!controller.signal.aborted) {
        if(replace&&revision===libraryRevision.current)hiddenUntilRefresh.current.clear();
        const available = {
          ...page,
          items:page.items.filter(item=>!hiddenUntilRefresh.current.has(item.id)).map(item=>{
            const starred=favoriteOverrides.current.get(item.id);
            return starred===undefined?item:{...item,starred};
          }),
        };
        if(previousParent.current===query.parent)rememberAnchor();
        setPages(previous=>replace?[available]:appendPage(previous,available,direction));
        setLoadedQuery(queryKey);
      }
    } catch (e) {if (!controller.signal.aborted) setError(errorText(e));}
    finally {controllers.current.delete(controller);if(browseRequest.current===controller){browseRequest.current=null;busy.current=false;if(!controller.signal.aborted)setLoading(false);}}
  }, [transport, workspace.id, query, queryKey, rememberAnchor]);
  useEffect(() => {
    // Replace only the browse request; toolbar portals and filter popovers stay mounted.
    browseRequest.current?.abort();browseRequest.current=null;busy.current=false;
    setSelection(new Map());
    if(previousParent.current!==query.parent){
      previousParent.current=query.parent;
      anchor.current=null;
      hiddenUntilRefresh.current.clear();
      if(scroll.current)scroll.current.scrollTop=0;
      setInspect(null);
    }
    void load('next', null, true);
    return()=>{browseRequest.current?.abort();};
  }, [load]);
  useEffect(()=>()=>{for(const controller of controllers.current)controller.abort();},[]);
  useEffect(() => {
    if (!scroll.current) return;
    const observer = new ResizeObserver(([entry]) => {
      if (!entry || entry.contentRect.width <= 0) return;
      setMeasured(true);
      if(entry.contentRect.width===current.current.width)return;
      rememberAnchor();
      setWidth(entry.contentRect.width);
    });
    observer.observe(scroll.current);
    return () => observer.disconnect();
  }, [rememberAnchor]);
  useLayoutEffect(()=>virtualizer.measure(),[rowHeight,rowEntries,virtualizer]);
  useLayoutEffect(() => {
    if (!loading && measured) props.onInitialReady();
  }, [loading, measured, props.onInitialReady]);
  useLayoutEffect(() => {
    const saved=anchor.current;
    if(!saved||!scroll.current)return;
    const row=rowEntries.find(value=>value.entries.some(entry=>entry.id===saved.id));
    if(row){
      const offsetHeight=saved.offset<0&&rowPaddingStart?rowPaddingStart:row.height;
      virtualizer.scrollToOffset(Math.max(0,scrollMargin+row.start+saved.offset*offsetHeight-saved.y));
    }
    anchor.current=null;
  },[rowEntries,rowPaddingStart,virtualizer,scrollMargin]);
  const visible = virtualizer.getVirtualItems();
  const last = visible.at(-1)?.index ?? -1;
  const first = visible[0]?.index ?? 0;
  const next = pages.at(-1)?.nextCursor ?? null;
  const previous = pages[0]?.previousCursor ?? null;
  useEffect(() => {
    if (loading || error || preview || bulkRefresh) return;
    if (!navigationPending && last >= rows - 3 && next) void load('next', next);
    else if (!navigationPending && first === 0 && previous) void load('previous', previous);
  }, [last, first, rows, next, previous, loading, error, load,preview,bulkRefresh,navigationPending]);
  useEffect(()=>{
    if(!bulkRefresh||loading||preview)return;
    setBulkRefresh(false);
    void load('next',null,true);
  },[bulkRefresh,load,loading,preview]);
  const [uploadRefresh,setUploadRefresh] = useState(false);
  useEffect(()=>{
    props.onSourceImportComplete.current=id=>{if(id===workspace.id&&query.view!=='trash')setUploadRefresh(true);};
    return()=>{props.onSourceImportComplete.current=null;};
  },[props.onSourceImportComplete,workspace.id,query.view]);
  useEffect(()=>{
    let timer:ReturnType<typeof setTimeout>|undefined;
    onTransferComplete.current = item=>{
      if(item.workspaceId!==workspace.id||query.view==='trash'||(query.view==='files'&&item.parentId!==query.parent&&!item.relativePath.includes('/')))return;
      clearTimeout(timer);
      timer=setTimeout(()=>setUploadRefresh(true),350);
    };
    return()=>{onTransferComplete.current=null;clearTimeout(timer);};
  },[onTransferComplete,query.parent,query.view,workspace.id]);
  useEffect(()=>{
    if(!uploadRefresh||loading||preview)return;
    setUploadRefresh(false);
    void load('next',null,true);
  },[load,loading,preview,uploadRefresh]);
  useEffect(()=>{
    if(preview||navigationPending)return;
    const ids=visible.flatMap(row=>rowEntries[row.index]?.entries??[]).filter(item=>item.renditionsRefreshing||[item.thumbnailState,item.previewState].some(state=>state==='queued'||state==='processing')).map(item=>item.id);
    if(ids.length===0||!transport.status)return;
    const controller=new AbortController();
    let timer:ReturnType<typeof setTimeout>;
    const refresh=async()=>{
      try{
        if(document.visibilityState==='hidden')return;
        const result=await transport.status(workspace.id,ids.slice(0,120),controller.signal,query.thumbnailPreset?{thumbnailPreset:query.thumbnailPreset}:undefined);
        if(controller.signal.aborted)return;
        const previousEntries=new Map(items.map(item=>[item.id,item]));
        const updates=new Map(result.items.filter(item=>{
          if(workspace.kind!=='application')return true;
          const previous=previousEntries.get(item.id);
          return previous?.versionId!==item.versionId||previous?.thumbnailState!==item.thumbnailState||previous?.thumbnailUrl!==item.thumbnailUrl;
        }).map(item=>[item.id,item]));
        if(updates.size===0)return;
        setPages(previous=>previous.map(page=>({...page,items:page.items.map(item=>{
          const updated=updates.get(item.id);
          if(!updated||updated.revision<item.revision)return item;
          const starred=favoriteOverrides.current.get(item.id);
          return starred===undefined?{...item,...updated}:{...item,...updated,starred};
        })})));
      }catch{
        // Keep rendered thumbnails and retry transient processing status errors.
      }finally{
        if(!controller.signal.aborted)timer=setTimeout(()=>void refresh(),2500);
      }
    };
    timer=setTimeout(()=>void refresh(),2500);
    return()=>{clearTimeout(timer);controller.abort();};
  },[items,visible,rowEntries,preview,navigationPending,transport,workspace.id,query.thumbnailPreset]);
  const changed = useCallback((entry:Entry)=>{
    setPages(previous=>previous.map(page=>({...page,items:page.items.map(item=>item.id===entry.id?{...item,...entry}:item)})));
    setInspect(current=>current?.id===entry.id?{...current,...entry}:current);
    if(entry.undoAvailable&&entry.operationId&&transport.folders?.undo){
      const operation=entry.operationId;
      props.toastQueue?.add({title:t.saved,variant:'success',actionProps:{children:t.drawingUndo,onPress:()=>{
        const controller=new AbortController();controllers.current.add(controller);
        void transport.folders!.undo!(workspace.id,operation,controller.signal).then(()=>setBulkRefresh(true)).catch(error=>props.toastQueue?.add({title:errorText(error),variant:'danger'})).finally(()=>controllers.current.delete(controller));
      }}},{timeout:12000});
    }
  },[transport,workspace.id,props.toastQueue,t]);
  const refresh = ()=>setBulkRefresh(true);
  const canWrite = pages[0]?.canWrite===true && workspace.permissions?.write!==false;
  const canUpload = workspace.permissions?.['uploads.create']!==false && (pages[0]?.canWrite ?? workspace.role !== 'viewer') && (query.view==='files'||query.view==='photos');
  const canSelect = canWrite&&!!transport.bulk&&props.mode!=='picker';
  const pickerChoice=props.pickerChoice;
  const selectedCount=pickerChoice?.selection.size??selection.size;
  const pickerSelectionFull=!!pickerChoice?.multiple&&selectedCount>=pickerChoice.limit;
  useMarquee({viewport: scroll, surface: contentStart, enabled: canSelect && !preview, selection, onChange: setSelection, limit: MAX_BULK_ITEMS});
  const canMove = canWrite && props.mode !== 'picker' && query.view !== 'trash' && workspace.permissions?.['files.update'] !== false;
  const moveItems = async (entries: BulkEntry[], parent: string | null, signal: AbortSignal) => {
    const first = entries[0];
    if (!first || (entries.length > 1 && !transport.bulk)) return;
    const optimistic = query.view === 'files' && parent !== query.parent;
    const moving = new Set(entries.map(item => item.id));
    if (optimistic) setPendingMoves(previous => new Set([...previous, ...moving]));
    try {
    const requestId = randomId();
    const receipt = transport.bulk
      ? await transport.bulk(workspace.id, {operation: 'move', items: entries, parent, requestId}, signal)
      : await transport.update(workspace.id, first.id, {revision: first.revision, parent, requestId}, signal).then(entry => ({items: [entry], operationId: entry.operationId, undoAvailable: entry.undoAvailable}));
    if (signal.aborted) return;
    if (receipt.undoAvailable && receipt.operationId && transport.folders?.undo) {
      const operationId = receipt.operationId;
      props.toastQueue?.add({title: t.move, variant: 'success', actionProps: {children: t.drawingUndo, onPress: () => {
        const controller = new AbortController();
        controllers.current.add(controller);
        void transport.folders!.undo!(workspace.id, operationId, controller.signal).then(() => refresh()).catch(error => props.toastQueue?.add({title: errorText(error), variant: 'danger'})).finally(() => controllers.current.delete(controller));
      }}});
    }
    const ids = new Set(receipt.items.map(item => item.id));
    setSelection(previous => new Map([...previous].filter(([id]) => !ids.has(id))));
    setDragMoveItems(null);
    if (optimistic) setPages(previous => previous.map(page => ({...page, items: page.items.filter(item => !ids.has(item.id))})));
    else refresh();
    } finally {
      // Removing an overlay exposes the latest data, never restores a stale snapshot.
      setPendingMoves(previous => new Set([...previous].filter(id => !moving.has(id))));
    }
  };
  const moveToFolder = (entries: BulkEntry[], parent: string) => {
    if (moveRequest.current) return;
    const controller = new AbortController();
    moveRequest.current = controller;
    controllers.current.add(controller);
    setMutating(true);
    void moveItems(entries, parent, controller.signal).catch(reason => {
      if (!controller.signal.aborted) setError(errorText(reason));
    }).finally(() => {
      controllers.current.delete(controller);
      if (moveRequest.current === controller) moveRequest.current = null;
      if (!controller.signal.aborted) setMutating(false);
    });
  };
  const dragCount = useMoveDrag({
    viewport: scroll, dropBar: moveDropBar, portal: portalContainer,
    enabled: canMove && !preview && !mutating && !dragMoveItems && !bulkOperation && !move,
    workspace:workspace.id, identity: JSON.stringify([workspace.id, query]), items, selection,
    onChoose: setDragMoveItems, onMove: moveToFolder,
  });
  const {onNavigate,recordVisit} = props;
  const toggleFavorite:ToggleFavorite = useCallback((entry,afterChange)=>{
    if(!transport.star||favoriteRequests.current.has(entry.id))return;
    const controller = new AbortController();
    controllers.current.add(controller);
    favoriteRequests.current.add(entry.id);
    setPendingFavorites(new Set(favoriteRequests.current));
    void transport.star(workspace.id,entry.id,entry.starred!==true,controller.signal).then(({starred})=>{
      if(controller.signal.aborted)return;
      if(favoriteOverrides.current.size>=MAX_BULK_ITEMS)favoriteOverrides.current.delete(favoriteOverrides.current.keys().next().value!);
      favoriteOverrides.current.set(entry.id,starred);
      setPages(previous=>previous.map(page=>({...page,items:page.items.map(item=>item.id===entry.id?{...item,starred}:item)})));
      setInspect(current=>current?.id===entry.id?{...current,starred}:current);
      if(afterChange&&previewActive.current)afterChange(starred);
      if((query.view==='starred'||(query.view==='photos'&&query.photos?.kind==='starred'))&&!starred){
        if(afterChange&&previewActive.current)starredRefresh.current=true;
        else {
          libraryRevision.current++;
          hiddenUntilRefresh.current.add(entry.id);
          setPages(previous=>previous.map(page=>({...page,items:page.items.filter(item=>item.id!==entry.id)})));
          setSelection(previous=>{const next=new Map(previous);next.delete(entry.id);return next;});
          setBulkRefresh(true);
        }
      }
    }).catch(error=>{
      if(!controller.signal.aborted)setError(errorText(error));
    }).finally(()=>{
      controllers.current.delete(controller);
      favoriteRequests.current.delete(entry.id);
      if(!controller.signal.aborted)setPendingFavorites(new Set(favoriteRequests.current));
    });
  },[query.view,query.photos?.kind,transport,workspace.id]);
  const openEntry = useCallback((entry:Entry,source:HTMLImageElement|null)=>{
    if (entry.mediaType === 'video' && ['queued', 'processing'].includes(entry.previewState ?? '')) return;
    if(query.view==='trash')setInspect(entry);
    else if(entry.kind==='folder')onNavigate(entry);
    else if(entry.mediaType==='note'&&props.mode!=='picker'){displayedFile.current=entry.id;onPreviewRoute(entry.id);setNoteDialog({entry});}
    else {
      previewActive.current=true;
      displayedFile.current = entry.id;
      onPreviewRoute(entry.id);
      setPreview({entry,rect:source?.getBoundingClientRect()??null,source});
      if(query.view!=='recent')recordVisit(workspace.id,entry.id);
    }
  },[onNavigate,onPreviewRoute,query.view,recordVisit,workspace.id,props.mode]);
  const toggleSelected = useCallback((entry:Entry,selected:boolean)=>{
    setSelection(previous=>{
      const next=new Map(previous);
      if(!selected)next.delete(entry.id);
      else if(next.size<MAX_BULK_ITEMS)next.set(entry.id,{id:entry.id,revision:entry.revision});
      return next;
    });
  },[]);
  const selectLoaded = ()=>{
    setSelection(previous=>{
      const next=new Map(previous);
      for(const entry of items){
        if(next.size>=MAX_BULK_ITEMS)break;
        if(entry.readOnly!==true&&!next.has(entry.id))next.set(entry.id,{id:entry.id,revision:entry.revision});
      }
      return next;
    });
  };
  const clipboardAlive=useRef(true),clipboardCopyBusy=useRef(false);
  useEffect(()=>{clipboardAlive.current=true;return()=>{clipboardAlive.current=false;};},[]);
  const shortcutUploadTarget=useRef({workspace:workspace.id,parent:query.parent});
  shortcutUploadTarget.current={workspace:workspace.id,parent:query.parent};
  const uploadClipboardFiles=(files:File[])=>{
    if(!files.length||!canUpload||props.upload?.enabled===false||navigationPending)return false;
    if(props.pickerMimeTypes?.length&&files.some(file=>!props.pickerMimeTypes!.includes(file.type))){setError(t.clipboardUnavailable);return false;}
    const target={workspaceId:workspace.id,parentId:query.parent};
    if(queue){queue.enqueue(files,target);}
    else{
      const controller=new AbortController();controllers.current.add(controller);setUploading(true);
      void (async()=>{for(const file of files){setUploadState(`${t.uploading} · ${file.name}`);await transport.upload(target.workspaceId,target.parentId,file,randomId(),controller.signal);}if(shortcutUploadTarget.current.workspace===target.workspaceId&&shortcutUploadTarget.current.parent===target.parentId)await load('next',null,true);})().catch(reason=>{if(!controller.signal.aborted)setError(errorText(reason));}).finally(()=>{controllers.current.delete(controller);if(!controller.signal.aborted){setUploading(false);setUploadState('');}});
    }
    return true;
  };
  useEffect(()=>{
    if(!shortcuts)return;
    const options=props.shortcuts===false?undefined:props.shortcuts;
    const clipboard=options?.clipboard===false?false:options?.clipboard??{};
    const limits=clipboardLimits(clipboard||{});
    const fail=(reason:unknown)=>{const key=errorText(reason);setError(key==='clipboardLimit'?t.clipboardLimit:key==='clipboardUnavailable'?t.clipboardUnavailable:key);};
    const ready=()=>!preview&&!navigationPending;
    const canPaste=()=>ready()&&canUpload&&props.upload?.enabled!==false&&clipboard!==false&&clipboard.paste!==false;
    const selected=()=>{const keys=pickerChoice?.selection??selection;if(keys.size!==1)return null;const entry=items.find(item=>keys.has(item.id));return entry?.kind==='file'&&typeof entry.width==='number'&&typeof entry.height==='number'&&entry.width>0&&entry.height>0&&entry.width*entry.height<=limits.maxPixels&&!entry.trashedAt&&['image/png','image/jpeg','image/webp','image/avif'].includes(entry.mimeType??'')?entry:null;};

    const dispose=[
      shortcuts.register('upload',()=>input.current?.click(),()=>ready()&&canUpload&&props.upload?.enabled!==false),
      shortcuts.register('newFolder',()=>setFolderDialog(true),()=>ready()&&canWrite&&workspace.permissions?.['folders.create']!==false&&query.view==='files'),
      shortcuts.register('selectAll',()=>{if(pickerChoice)pickerChoice.selectLoaded(items);else selectLoaded();},()=>ready()&&(canSelect||pickerChoice?.enabled===true)),
      shortcuts.register('clearSelection',()=>{if(pickerChoice)pickerChoice.clear();else setSelection(new Map());},()=>ready()&&selectedCount>0),
      shortcuts.register('copyImage',()=>{
        const entry=selected();if(!entry||clipboardCopyBusy.current)return false;clipboardCopyBusy.current=true;
        const controller=new AbortController();controllers.current.add(controller);
        void copyImageToClipboard(transport.contentUrl(workspace.id,entry.id,true,entry.versionId??undefined),limits,controller.signal).then(()=>{if(!controller.signal.aborted)props.toastQueue?.add({title:t.clipboardCopied,variant:'success'},{timeout:3000});}).catch(reason=>{if(!controller.signal.aborted)fail(reason);}).finally(()=>{controllers.current.delete(controller);clipboardCopyBusy.current=false;});
      },()=>ready()&&clipboard!==false&&clipboard.copyImages!==false&&workspace.permissions?.['files.download']!==false&&selected()!==null),
      shortcuts.register('pasteFiles',()=>{const destination=shortcutUploadTarget.current;void readClipboardFiles(limits).then(files=>{if(clipboardAlive.current&&canPaste()&&shortcutUploadTarget.current.workspace===destination.workspace&&shortcutUploadTarget.current.parent===destination.parent)uploadClipboardFiles(files);}).catch(reason=>{if(clipboardAlive.current)fail(reason);});},canPaste),
    ];
    const paste=(event:ClipboardEvent)=>{
      if(!canPaste()||!event.clipboardData)return false;
      try{const files=clipboardFiles(event.clipboardData,limits);if(!files.length)return false;return uploadClipboardFiles(files);}catch(reason){fail(reason);return true;}
    };
    shortcuts.onPaste=paste;
    return()=>{dispose.forEach(fn=>fn());if(shortcuts.onPaste===paste)shortcuts.onPaste=undefined;};
  });
  const closePreview = ()=>{
    previewActive.current=false;
    displayedFile.current = null;
    onPreviewRoute(null, true);
    setPreview(null);
    if(starredRefresh.current){
      starredRefresh.current=false;
      setBulkRefresh(true);
    }
  };
  const entryActions = useMemo<EntryActionHandlers>(() => ({
    downloadFolders:!!transport.archiveUrl,
    inspect: setInspect,
    ...(canWrite && workspace.permissions?.['shares.manage']!==false && transport.management?.createShare ? {share: (entry:Entry)=>setShareEntries([entry])} : {}),
    ...(canWrite && workspace.permissions?.['files.update']!==false ? {move: setMove} : {}),
    ...(canWrite && workspace.permissions?.['files.trash']!==false ? {trash: setTrash} : {}),
    ...(workspace.permissions?.['files.download']!==false ? {download: (entry:Entry) => { if(entry.kind === 'folder' && !transport.archiveUrl)return; window.location.href = entry.kind === 'folder' ? transport.archiveUrl!(workspace.id, [entry.id]) : transport.contentUrl(workspace.id, entry.id, true, entry.versionId ?? undefined); }} : {}),
    ...(props.previewLink ? {copy: (entry: Entry) => {
      const url = props.previewLink?.(entry);
      if (url) void navigator.clipboard?.writeText(url).catch(reason => setError(errorText(reason)));
    }} : {}),
    ...(canWrite && workspace.permissions?.['files.optimize']!==false && transport.optimize ? {optimize: (entry: Entry) => {
      setOptimizationError('');
      setOptimization({entry, prune: false});
    }} : {}),
  }), [canWrite, changed, props.previewLink, transport, workspace.id, workspace.permissions]);
  const bulkCompleted = (updated:Entry[],parent:string|null)=>{
    libraryRevision.current++;
    const remove = bulkOperation!=='move'||(query.view==='files'&&parent!==query.parent);
    if(remove){
      const ids=new Set(updated.map(item=>item.id));
      for(const id of ids)hiddenUntilRefresh.current.add(id);
      setPages(previous=>previous.map(page=>({...page,items:page.items.filter(item=>!ids.has(item.id))})));
    }else{
      const updates=new Map(updated.map(item=>[item.id,item]));
      setPages(previous=>previous.map(page=>({...page,items:page.items.map(item=>{
        const result=updates.get(item.id);
        return result?{...item,...result}:item;
      })})));
    }
    setSelection(new Map());
    setBulkRefresh(true);
  };
  const assistantDock=useAssistantPreview();
  const dockButton=(label:string,icon:typeof Add01Icon,press:()=>void)=><Tooltip delay={300}><Button isIconOnly variant="ghost" aria-label={label} onPress={press}><Icon icon={icon} size={24}/></Button><Tooltip.Content UNSTABLE_portalContainer={portalContainer}>{label}</Tooltip.Content></Tooltip>;
  const libraryDock=<>{canUpload&&props.upload?.enabled!==false&&<LibraryCreateOverlay portal={portalContainer} t={t} upload={()=>input.current?.click()} folder={()=>setFolderDialog(true)} note={!props.pickerMimeTypes&&transport.createNote&&workspace.permissions?.['notes.create']!==false?()=>setNoteDialog({entry:null}):undefined}/>}{dockButton(t.search,Search01Icon,()=>{if(props.onSearch)props.onSearch();else{const root=portalContainer.getRootNode() as Document|ShadowRoot;root.querySelector<HTMLInputElement>('.fms-header-search input')?.focus();}})}{assistantDock.enabled&&<><span role="separator" aria-orientation="vertical" className="fms-preview-action-separator"/><AssistantToggle locale={props.locale??'en'}/></>}</>;
  const selectionBar=(canSelect||pickerChoice?.enabled)&&selectedCount>0?<SelectionBar label={t.selection} count={t.selectedCount.replace('{count}',new Intl.NumberFormat(props.locale).format(selectedCount))} portal={portalContainer} actions={[
      ...(pickerChoice?[]:query.view==='trash'?[{id:'restore',label:t.restore,icon:Undo02Icon,run:()=>setBulkOperation('restore')}]:[
        ...(workspace.permissions?.['files.download']!==false&&transport.archiveUrl?[{id:'download',label:t.download,icon:Download01Icon,run:()=>{window.location.href=transport.archiveUrl!(workspace.id,[...selection.keys()]);}}]:[]),
        ...(workspace.permissions?.['files.download']!==false&&workspace.backgroundArchives&&transport.createArchive?[{id:'archive',label:t.prepareArchive,icon:FileZipIcon,run:()=>{const controller=new AbortController();void transport.createArchive!(workspace.id,[...selection.keys()],controller.signal).then(()=>setArchiveRefresh(n=>n+1)).catch(e=>setError(errorText(e)));}}]:[]),
        ...(workspace.permissions?.['shares.manage']!==false&&transport.management?.createShare?[{id:'share',label:t.share,icon:Share08Icon,disabled:items.filter(item=>selection.has(item.id)).length!==selection.size,run:()=>setShareEntries(items.filter(item=>selection.has(item.id)))}]:[]),
        ...(workspace.permissions?.['files.update']!==false?[{id:'move',label:t.move,icon:FolderMoveToIcon,run:()=>setBulkOperation('move')}]:[]),
        ...(workspace.permissions?.['files.trash']!==false?[{id:'trash',label:t.moveToTrash,icon:Delete02Icon,danger:true,run:()=>setBulkOperation('trash')}]:[]),
      ]),
      {id:'clear',label:t.deselect,icon:Cancel01Icon,run:pickerChoice?.clear??(()=>setSelection(new Map()))},
    ] satisfies SelectionAction[]}/>:null;

  return <PersistentActionDock content={libraryDock} width={12+(canUpload&&props.upload?.enabled!==false?46:0)+40+(assistantDock.enabled?53:0)} label={t.previewActions} enabled={props.mode!=='picker'&&props.previewActions!==false&&!dragMoveItems}>
    {props.actionSlot&&createPortal(canUpload&&!(props.mode==='picker'&&props.upload?.enabled===false)?<div className="fms-creation-gate" inert={navigationPending} aria-busy={navigationPending}><LibraryCreationActions portal={portalContainer} t={t} upload={props.upload?.enabled!==false?()=>input.current?.click():undefined} uploadFolder={queue&&props.upload?.enabled!==false&&props.upload?.allowFolders!==false?()=>folderInput.current?.click():undefined} createFolder={()=>setFolderDialog(true)} note={!props.pickerMimeTypes&&transport.createNote&&workspace.permissions?.['notes.create']!==false?()=>setNoteDialog({entry:null}):undefined}/></div>:null,props.actionSlot)}
    {!isPhotos&&props.titleCountSlot&&createPortal(<span title={t.loadedItems.replace('{count}',new Intl.NumberFormat(props.locale).format(items.length))} aria-label={t.loadedItems.replace('{count}',new Intl.NumberFormat(props.locale).format(items.length))}>{t.loadedItems.replace('{count}',new Intl.NumberFormat(props.locale).format(items.length))}</span>,props.titleCountSlot)}
    {!isPhotos&&props.toolSlot&&createPortal(<>
      {(canSelect&&items.some(item=>item.readOnly!==true)||pickerChoice?.enabled&&pickerChoice.limit>1&&items.some(item=>item.kind==='file'&&item.versionId))&&<Tooltip delay={300}><Button isIconOnly variant="tertiary" size="sm" className="fms-select-loaded" aria-label={t.selectLoaded} isDisabled={navigationPending||(pickerChoice?pickerSelectionFull:selection.size>=MAX_BULK_ITEMS)} onPress={pickerChoice?()=>pickerChoice.selectLoaded(items):selectLoaded}><Icon icon={TickDouble02Icon} size={19}/></Button><Tooltip.Content UNSTABLE_portalContainer={portalContainer}>{t.selectLoaded}</Tooltip.Content></Tooltip>}

      {props.sorter}<LayoutControl layout={layout} onChange={value=>{rememberAnchor();setLayout(value);}} portal={portalContainer} t={t}/>
    </>,props.toolSlot)}
    <input ref={input} type="file" hidden multiple accept={props.accept} onChange={event=>{const files=Array.from(event.target.files??[]);event.target.value='';if(!files.length||!canUpload||props.upload?.enabled===false)return;if(queue){try{queue.enqueue(files,{workspaceId:workspace.id,parentId:query.parent});}catch(error){setError(errorText(error));}}else{const controller=new AbortController();controllers.current.add(controller);setUploading(true);void (async()=>{for(const file of files){setUploadState(`${t.uploading} · ${file.name}`);await transport.upload(workspace.id,query.parent,file,randomId(),controller.signal);}await load('next',null,true);})().catch(e=>setError(errorText(e))).finally(()=>{controllers.current.delete(controller);setUploading(false);setUploadState('');});}}}/>
    <input ref={folderInput} type="file" hidden multiple {...{webkitdirectory:''}} onChange={event=>{const files=Array.from(event.target.files??[]);event.target.value='';if(queue&&canUpload&&props.upload?.enabled!==false&&props.upload?.allowFolders!==false)try{queue.enqueue(files,{workspaceId:workspace.id,parentId:query.parent});}catch(error){setError(errorText(error));}}}/>
    {selectionBar&&(props.selectionSlot?createPortal(selectionBar,props.selectionSlot):selectionBar)}
    {workspace.backgroundArchives&&archiveJobs.length>0&&<section aria-label={t.archiveJobs} className="fms-upload-status"><strong>{t.archiveJobs}</strong>{archiveJobs.map(job=><span key={job.id}> {job.state==='ready'?t.archiveReady:job.state==='failed'?t.archiveFailed:job.state==='processing'?t.archiveProcessing:t.archiveQueued}{job.state==='ready'&&transport.archiveResultUrl&&<> · <a href={transport.archiveResultUrl(workspace.id,job.id)}>{t.download}</a></>}</span>)}</section>}
    {uploadState && <div className="fms-upload-status" role="status">{uploading && <Spinner size="sm"/>}{uploadState}</div>}
    {error && <div className="fms-error fms-inline-error" role="alert">{error}<Button size="sm" variant="tertiary" onPress={() => void load('next', null, true)}>{t.retry}</Button></div>}
    {dragCount>0&&<div ref={moveDropBar} className="fms-move-drop-bar" role="status">{t.dropToMove}</div>}
    <motion.div layoutScroll ref={node=>{contentStart.current=node;if(!props.scrollViewport)ownScroll.current=node;}} className={`fms-scroll ${isPhotos?'fms-photos-scroll':'is-'+layout}`} aria-label={t.library} aria-busy={navigationPending||loading} tabIndex={0}>
      {navigationPending||items.length === 0 ? <div className="fms-centered">{navigationPending||loading ? <><Spinner/><span role="status">{t.loading}</span></> : <><span className="fms-empty-icon"><Icon icon={query.view === 'photos' ? Image01Icon : Folder01Icon} size={52}/></span><h2>{query.search || query.view !== 'files' ? t.noResults : t.empty}</h2>{query.view==='files'&&!query.search&&<p className="fms-muted">{workspace.kind==='application'?t.applicationFilesEmpty:t.emptyHint}</p>}</>}</div> : <div className={isPhotos?'fms-photo-canvas':'fms-virtual'} style={{height:virtualizer.getTotalSize(),'--fms-columns':columns} as CSSProperties}>
        {isPhotos&&visible.map(row=>{const group=rowEntries[row.index];return group?.label!==undefined?<h2 className="fms-photo-period" data-photo-heading={group.key} key={group.key} style={{top:row.start-scrollMargin}}>{group.label}</h2>:null;})}
        <div className={isPhotos?'fms-photo-virtual':'fms-entry-layer'} style={isPhotos?{height:'100%','--fms-columns':columns} as CSSProperties:undefined}>
        {visible.flatMap(row=>{
          const group=rowEntries[row.index];
          if(!group)return [];
          if(isPhotos&&group.label!==undefined)return [];
          return group.entries.map((entry,column)=>isPhotos?
            <PhotoCard key={entry.id} entry={entry} t={t} density={columns} reducedMotion={reducedMotion??false} top={row.start-scrollMargin} column={column} cell={photoGeometry.cell} aspect={props.photoAspect} open={openEntry}
              canMove={canMove&&entry.readOnly!==true&&!entry.trashedAt} selected={selection.has(entry.id)} canSelect={canSelect&&entry.readOnly!==true} selectionFull={selection.size>=MAX_BULK_ITEMS} onSelect={toggleSelected}
              toggleFavorite={transport.star&&!entry.trashedAt?toggleFavorite:undefined} favoritePending={pendingFavorites.has(entry.id)} actions={entryActions} portal={portalContainer}/>:
            <EntryCard picker={props.mode==='picker'} key={entry.id} entry={entry} t={t} layout={layout} open={openEntry} top={row.start-scrollMargin} column={column}
              canMove={canMove&&entry.readOnly!==true&&!entry.trashedAt} selected={pickerChoice?.selection.has(entry.id)??selection.has(entry.id)} canSelect={pickerChoice?pickerChoice.enabled&&entry.kind==='file'&&!!entry.versionId&&!entry.trashedAt:canSelect&&entry.readOnly!==true} selectionFull={pickerChoice?pickerSelectionFull:selection.size>=MAX_BULK_ITEMS} onSelect={pickerChoice?.toggle??toggleSelected}
              toggleFavorite={transport.star&&!entry.trashedAt?toggleFavorite:undefined} favoritePending={pendingFavorites.has(entry.id)} actions={entryActions} portal={portalContainer}/>
          );
        })}
        </div>
      </div>}
    </motion.div>
    <Modal><Modal.Backdrop isOpen={optimization !== null} isDismissable={!optimizing} isKeyboardDismissDisabled={optimizing}
      onOpenChange={open => {if (!open && !optimizing) setOptimization(null);}} UNSTABLE_portalContainer={portalContainer}>
      <Modal.Container size="md" placement="center"><Modal.Dialog>
        <Modal.CloseTrigger isDisabled={optimizing}/>
        <Modal.Header><Modal.Heading>{t.optimize}</Modal.Heading></Modal.Header>
        <Modal.Body>
          <p>{optimization && fileNameParts(optimization.entry).title}</p><p className="fms-muted">{t.optimizeHint}</p>
          <Checkbox isSelected={optimization?.prune ?? false} isDisabled={optimizing} onChange={prune => setOptimization(value => value && {...value, prune})}>
            <Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Checkbox.Content>{t.pruneReplaced}</Checkbox.Content>
          </Checkbox>
          {optimizationError && <p role="alert" className="fms-error">{optimizationError}</p>}
        </Modal.Body>
        <Modal.Footer><Button isDisabled={optimizing} onPress={() => {
          if (!optimization || !transport.optimize || optimizing) return;
          const controller = new AbortController();
          controllers.current.add(controller);
          setOptimizing(true);
          setOptimizationError('');
          const {entry, prune} = optimization;
          void transport.optimize(workspace.id, entry.id, entry.revision, controller.signal, {replace: true, retention: prune ? 'prune' : 'keep'})
            .then(result => {if (!controller.signal.aborted) {changed(result); setOptimization(null);}})
            .catch(reason => {if (!controller.signal.aborted) setOptimizationError(errorText(reason));})
            .finally(() => {controllers.current.delete(controller); if (!controller.signal.aborted) setOptimizing(false);});
        }}>{optimizing ? <Spinner size="sm"/> : t.optimize}</Button></Modal.Footer>
      </Modal.Dialog></Modal.Container>
    </Modal.Backdrop></Modal>
    {shareEntries&&transport.management&&<ShareDialog workspace={workspace.id} entries={shareEntries} {...(props.user?.name?{actor:{name:props.user.name,owner:workspace.role==='owner'}}:{})} guestUploads={workspace.guestUploads===true} transport={transport.management} portal={portalContainer} locale={props.locale??'en'} onClose={()=>setShareEntries(null)}/>}
    {folderDialog&&<CreateDestinationDialog workspace={workspace.id} transport={transport} t={t} portal={portalContainer} parent={query.parent} path={props.folderPath??[]} onClose={()=>setFolderDialog(false)} onCreated={async()=>{await load('next',null,true);setFolderDialog(false);}}/>}
    {bulkOperation&&canSelect&&<BulkDialog operation={bulkOperation} items={[...selection.values()]} workspace={workspace.id} transport={transport}
      portal={portalContainer} t={t} onPending={(ids,pending)=>setPendingMoves(previous=>{const next=new Set(previous);for(const id of ids){if(pending)next.add(id);else next.delete(id);}return next;})} onClose={()=>setBulkOperation(null)} onComplete={bulkCompleted} moveSubmit={(parent,signal)=>moveItems([...selection.values()],parent,signal)}/>}
    {noteDialog&&<NoteComposer workspace={workspace.id} parent={query.parent} entry={noteDialog.entry} transport={transport} portal={portalContainer} pl={props.locale==='pl'} editable={canWrite&&workspace.permissions?.[noteDialog.entry?'notes.update':'notes.create']!==false} onClose={()=>{setNoteDialog(null);onPreviewRoute(null,true);displayedFile.current=null;}} onSaved={entry=>{setNoteDialog(null);onPreviewRoute(null,true);displayedFile.current=null;void load('next',null,true);}}/>}
    {props.mode==='picker' ? (props.pickerPreviewLayer&&<FilePreview anchor={preview} workspace={workspace.id} query={query}
      options={{...props,comments:false,previewActions:false}} inlineTarget={props.pickerPreviewLayer} portal={portalContainer} t={t}
      pickerSelect={pickerChoice?.enabled?(entry=>pickerChoice.toggle(entry,true)):undefined} pickerSelection={pickerChoice?.selection}
      pickerSelectionFull={pickerSelectionFull} actions={entryActions.download?{download:entryActions.download}:{}}
      canWrite={false} allowDownload={workspace.permissions?.['files.download']!==false} showTrash={false} allowTrash={false}
      onClose={closePreview} onNavigateEntry={entry=>{displayedFile.current=entry.id;onPreviewRoute(entry.id,true);}}
      onChanged={changed} onTrash={setTrash} toggleFavorite={undefined} pendingFavorites={pendingFavorites}/>) : <FilePreview actions={entryActions} allowShare={canWrite&&workspace.kind!=='application'&&workspace.permissions?.['shares.manage']!==false} guestUploads={workspace.guestUploads===true} showTrash={workspace.kind!=='application'} allowTrash={workspace.permissions?.['files.trash']!==false} allowDownload={workspace.permissions?.['files.download']!==false} anchor={preview} workspace={workspace.id} query={query} options={{...props,comments:props.comments!==false&&workspace.commentsEnabled!==false&&workspace.permissions?.['comments.view']!==false}} canWrite={canWrite} portal={portalContainer} t={t} onClose={closePreview} onNavigateEntry={entry => {displayedFile.current = entry.id; onPreviewRoute(entry.id, true);}} onChanged={changed} onTrash={setTrash} toggleFavorite={transport.star?toggleFavorite:undefined} pendingFavorites={pendingFavorites}/>}
    <Modal><Modal.Backdrop isOpen={inspect!==null} onOpenChange={open=>{if(!open)setInspect(null);}} UNSTABLE_portalContainer={portalContainer}><Modal.Container size="md" placement="center"><Modal.Dialog><div ref={setInspectorOverlays}/><Modal.CloseTrigger/><Modal.Header><Modal.Heading>{inspect && fileNameParts(inspect).title}</Modal.Heading></Modal.Header><Modal.Body>{inspect&&<EntryInspector key={inspect.id} portal={portalContainer} tagPortal={inspectorOverlays??portalContainer} comments={props.comments!==false&&workspace.commentsEnabled!==false&&workspace.permissions?.['comments.view']!==false} entry={inspect} workspace={workspace.id} transport={transport} canWrite={canWrite&&!inspect.trashedAt&&inspect.readOnly!==true} onChanged={changed} t={t}/>}</Modal.Body>{inspect?.trashedAt&&canWrite&&inspect.readOnly!==true&&<Modal.Footer><Button isDisabled={mutating} onPress={()=>{if(!inspect)return;const controller=new AbortController();controllers.current.add(controller);setMutating(true);void transport.restore(workspace.id,inspect.id,inspect.revision,controller.signal).then(()=>{setInspect(null);refresh();}).catch(e=>setError(errorText(e))).finally(()=>{controllers.current.delete(controller);setMutating(false);});}}>{t.restore}</Button></Modal.Footer>}</Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>
    {dragMoveItems&&<DestinationDialog workspace={workspace.id} transport={transport} portal={portalContainer} t={t}
      excludedIds={dragMoveItems.map(item=>item.id)} onClose={()=>setDragMoveItems(null)}
      submit={(parent,signal)=>moveItems(dragMoveItems,parent,signal)}/>}
    <MoveDialog submit={async(entry,parent,signal)=>{await moveItems([{id:entry.id,revision:entry.revision}],parent,signal);setMove(null);setInspect(null);}} entry={move} workspace={workspace.id} transport={transport} portal={inspectorOverlays??portalContainer} t={t} onClose={()=>setMove(null)} onChanged={()=>{setInspect(null);refresh();}}/>
    <Modal><Modal.Backdrop isOpen={trash!==null} onOpenChange={open=>{if(!open&&!mutating)setTrash(null);}} UNSTABLE_portalContainer={portalContainer}><Modal.Container size="sm" placement="center"><Modal.Dialog><Modal.CloseTrigger isDisabled={mutating}/><Modal.Header><Modal.Heading>{t.deleteTitle}</Modal.Heading></Modal.Header><Modal.Body><p>{t.deleteHint}</p></Modal.Body><Modal.Footer><Button variant="danger" isDisabled={mutating} onPress={()=>{if(!trash)return;const controller=new AbortController();controllers.current.add(controller);setMutating(true);const id=trash.id;setPendingMoves(previous=>new Set([...previous,id]));void transport.trash(workspace.id,id,trash.revision,controller.signal).then(()=>{setPages(previous=>previous.map(page=>({...page,items:page.items.filter(item=>item.id!==id)})));setTrash(null);setInspect(null);closePreview();refresh();}).catch(e=>setError(errorText(e))).finally(()=>{setPendingMoves(previous=>{const next=new Set(previous);next.delete(id);return next;});controllers.current.delete(controller);setMutating(false);});}}>{mutating?<Spinner size="sm"/>:t.moveToTrash}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>

  </PersistentActionDock>;
}

const EntryCard = memo(function EntryCard({entry,t,layout,open,actions,portal,top,column,selected,canSelect,selectionFull,onSelect,toggleFavorite,favoritePending,picker,canMove}:{
  entry:Entry;
  t:Messages;
  layout:LibraryLayout;
  open:(entry:Entry,source:HTMLImageElement|null)=>void;
  picker?: boolean;
  canMove:boolean;
  actions: EntryActionHandlers;
  portal: HTMLDivElement;
  top:number;
  column:number;
  selected:boolean;
  canSelect:boolean;
  selectionFull:boolean;
  onSelect:(entry:Entry,selected:boolean)=>void;
  toggleFavorite:ToggleFavorite|undefined;
  favoritePending:boolean;
}){
  const folder = entry.kind==='folder';
  const image = entry.mediaType==='image';
  const audio = entry.mediaType==='audio';
  const processing = entry.mediaType === 'video' && ['queued', 'processing'].includes(entry.previewState ?? '');
  const thumbnail = useRef<HTMLImageElement>(null);
  const [failedThumbnail,setFailedThumbnail]=useState<string|null>(null);
  const cardClass = ['fms-entry',layout==='list'?'is-list':layout==='large-grid'?'is-large-grid':'',selected?'is-selected':'',canSelect?'can-select':'',toggleFavorite?'can-favorite':''].filter(Boolean).join(' ');
  return <div data-move-entry={canMove?entry.id:undefined} data-move-folder={canMove&&folder?entry.id:undefined} data-preview-entry={entry.id} data-select-entry={canSelect?entry.id:undefined} data-select-revision={entry.revision} className={cardClass} style={{top,'--fms-column':column} as CSSProperties}>
    <Button variant="ghost" className="fms-card" isDisabled={processing} onPress={()=>open(entry,thumbnail.current)} aria-label={`${folder?t.folder:t.preview}: ${entry.name}`}>
      <div className={`fms-card-art ${folder?'is-folder':image?'is-image':audio?'is-audio':''}`}>
        {audio?<AudioArtwork samples={entry.audioWaveform} compact={layout==='list'}/>:entry.mediaType==='note'?<div className="fms-note-card" style={{backgroundColor:entry.noteBackground??'#ffffff'}}><p>{entry.noteExcerpt}</p></div>:entry.thumbnailUrl&&failedThumbnail!==entry.thumbnailUrl?<img ref={thumbnail} src={entry.thumbnailUrl} alt="" loading="lazy" decoding="async" onError={()=>setFailedThumbnail(entry.thumbnailUrl??null)}/>:folder?<img className="fms-folder-artwork" src={folderArtwork} alt="" width={layout==='list'?32:128} height={layout==='list'?32:128}/>:<FileTypeIcon entry={entry} size={layout==='list'?24:56}/>}
      </div>
      <div className="fms-card-caption">
        <strong title={fileNameParts(entry).title}>{fileNameParts(entry).title}</strong>
        <span>{processing?t.processingVideo:folder?[t.folder,entry.sourceLabel].filter(Boolean).join(' · '):entry.mediaType==='note'?'Note':fileCaption(entry)||t.document}</span>
      </div>
      {layout==='list'&&<time>{new Date(entry.updatedAt).toLocaleDateString()}</time>}
    </Button>
    {canSelect&&<Checkbox className="fms-card-checkbox" aria-label={`${t.selectItem}: ${entry.name}`}
      isSelected={selected} isDisabled={selectionFull&&!selected} onChange={checked=>onSelect(entry,checked)}>
      <Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control></Checkbox.Content>
    </Checkbox>}
    {!picker&&<EntryActions className="fms-card-actions" entry={entry} portal={portal} t={t} handlers={actions}
      open={()=>open(entry,thumbnail.current)} toggle={toggleFavorite ? () => toggleFavorite(entry) : undefined} pending={favoritePending}/>}
  </div>;
});
