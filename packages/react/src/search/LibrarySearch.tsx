import {useShortcutRegistry} from '../shortcuts/context';
import {Button, Dropdown, Label, SearchField, Spinner, Modal} from '@heroui/react';
import {ArrowDown01Icon, File01Icon, FilterHorizontalIcon, Folder01Icon, Search01Icon, XIcon} from '@hugeicons/core-free-icons';
import {useEffect, useId, useRef, useState} from 'react';
import {useVirtualizer} from '@tanstack/react-virtual';
import type {BrowseExtensions, BrowseFilters, Entry, StudioTransport, Workspace} from '../contracts';
import {FilterItems} from '../components/library/BrowseControls';
import {Icon, type Messages} from '../ui';
import type {LibrarySearchResult, LibrarySearchTransport} from './contracts';
import {useLibrarySearch} from './useLibrarySearch';

type SearchChoice = 'auto'|'exact'|'index'|'semantic';

export interface LibrarySearchProps {
  spotlight?: boolean;
  isOpen?: boolean;
  onOpenChange?: (value:boolean)=>void;
  workspace: Workspace;
  workspaces: Workspace[];
  multiple: boolean;
  indexed: boolean;
  semantic?: boolean;
  transport: StudioTransport;
  search: LibrarySearchTransport;
  extensions: BrowseExtensions;
  portal: HTMLDivElement;
  t: Messages;
  onOpen: (workspace: string, entry: Entry) => void;
}

function SearchEmpty({title, description, recent = false}: {title: string; description: string; recent?: boolean}) {
  return <div className="fms-search-empty" role="status">
    <span className="fms-search-empty-icon" aria-hidden="true">
      <Icon icon={recent ? Folder01Icon : Search01Icon} size={18}/>
    </span>
    <strong>{title}</strong>
    <p>{description}</p>
  </div>;
}

export function LibrarySearch({workspace, workspaces, multiple, indexed, semantic=false, transport, search, extensions, portal, t, onOpen, spotlight=false, isOpen, onOpenChange}: LibrarySearchProps) {
  const shortcutRegistry=useShortcutRegistry();
  const [localOpen, setLocalOpen] = useState(false);
  const open=isOpen??localOpen;
  const setOpen=(value:boolean)=>{setLocalOpen(value);onOpenChange?.(value);};
  const [showFilters, setShowFilters] = useState(false);
  const [text, setText] = useState('');
  const [scopeId, setScopeId] = useState(workspace.id);
  const [filters, setFilters] = useState<BrowseFilters>({});
  const [customFilters, setCustomFilters] = useState<Record<string,string>>({});
  const [preferredMode, setPreferredMode] = useState<SearchChoice>('exact');
  const [kind, setKind] = useState<'file'|'folder'>();
  const [history, setHistory] = useState<string[]>([]);
  const [filterPortal, setFilterPortal] = useState<HTMLDivElement|null>(null);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [recentHeight, setRecentHeight] = useState(0);
  const panel = useRef<HTMLDivElement>(null);
  const root = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const scroll = useRef<HTMLDivElement>(null);
  const id = useId();
  const scope = (multiple ? workspaces.find(item=>item.id===scopeId) : null) ?? workspace;
  const term = text.trim();
  const hasIndex = scope.indexedSearch ?? indexed;
  const hasSemantic = hasIndex && (scope.semanticSearch ?? semantic);
  const modes: SearchChoice[] = hasSemantic ? ['exact','auto','index','semantic'] : hasIndex ? ['exact','auto','index'] : ['exact'];
  const choice = modes.includes(preferredMode) ? preferredMode : modes[0]!;
  const labels: Record<SearchChoice,string> = {auto:t.searchSmart,exact:t.searchUseExact,index:t.searchUseIndex,semantic:t.searchSemantic};
  const requestedMode = choice==='auto' ? 'exact' : choice;
  const recent = !term && (requestedMode!=='exact' || (!kind && !Object.keys(filters).length && !Object.keys(customFilters).length));
  const query = {text:term, ...(kind?{kind}:{}), filters, customFilters};
  const direct = useLibrarySearch(search, scope.id, {...query,mode:requestedMode}, open&&!recent, requestedMode==='exact'?180:480);
  const expand = open && !recent && choice==='auto' && !term.startsWith('#') && Array.from(term).length>=3
    && direct.settled && !direct.loading && !direct.failed && !direct.items.length && !direct.cursor;
  const expandedMode = hasSemantic ? 'hybrid' : 'index';
  const expanded = useLibrarySearch(search, scope.id, {...query,mode:expandedMode}, expand, 260);
  const results = expand ? expanded : direct;
  const mode = expand ? expandedMode : requestedMode;
  const loadingResults = results.loading || (!results.settled && !results.failed);
  const folders = useLibrarySearch(search, scope.id, {text:'', kind:'folder', limit:3}, open&&recent);
  const files = useLibrarySearch(search, scope.id, {text:'', kind:'file', limit:3}, open&&recent);
  const uploaders = !!transport.members && scope.permissions?.['members.view'] === true;
  const virtualizer = useVirtualizer({
    count: open && !recent ? results.items.length : 0,
    getScrollElement: () => scroll.current,
    estimateSize: () => 54,
    getItemKey: index => results.items[index]?.id ?? index,
    overscan: 6,
  });
  const rows = virtualizer.getVirtualItems();
  const lastRow = rows.at(-1)?.index ?? -1;
  const {loadMore} = results;
  useEffect(()=>{
    if(open&&!recent&&results.cursor&&!results.loading&&!results.failed&&lastRow>=results.items.length-5)loadMore();
  },[open,recent,results.cursor,results.loading,results.failed,results.items.length,lastRow,loadMore]);
  useEffect(()=>{
    scroll.current?.scrollTo({top:0});
    setActiveIndex(-1);
  },[results.key]);

  useEffect(()=>{
    const node=root.current??portal;
    const doc=node.ownerDocument;
    const studio=node.closest('.fms-root');
    const outside=(event:PointerEvent)=>{if(!spotlight&&!event.composedPath().includes(node))setOpen(false);};
    const shortcuts=(event:KeyboardEvent)=>{
      if(event.defaultPrevented||event.isComposing||event.altKey||event.metaKey||event.ctrlKey)return;
      const path=event.composedPath();
      if(!path.includes(studio!)&&event.target!==doc.body)return;
      if(event.key==='Escape'&&open&&path.includes(node)&&!path.some(el=>el instanceof Element&&el.matches('.popover'))){
        event.preventDefault();
        setOpen(false);
        input.current?.blur();
      }
      if(shortcutRegistry)return;
      if(event.key.toLowerCase()!=='s'||path.some(el=>el instanceof Element&&el.matches('input,textarea,select,[contenteditable]:not([contenteditable="false"]),[role=dialog]')))return;
      const shadow=node.getRootNode();
      if(shadow instanceof ShadowRoot&&shadow.querySelector('[data-slot="modal-backdrop"]'))return;
      event.preventDefault();
      setOpen(true);
      input.current?.focus();
    };
    doc.addEventListener('pointerdown',outside,true);
    doc.addEventListener('keydown',shortcuts);
    return()=>{doc.removeEventListener('pointerdown',outside,true);doc.removeEventListener('keydown',shortcuts);};
  },[open,portal,spotlight,shortcutRegistry]);

  useEffect(()=>{
    if(!open||!spotlight)return;
    const frame=requestAnimationFrame(()=>input.current?.focus());
    return()=>cancelAnimationFrame(frame);
  },[open,spotlight]);

  const changeText=(value:string)=>{
    setOpen(true);
    setKind(undefined);
    setText(value);
  };
  const remember=()=>{
    if(term)setHistory(previous=>[term,...previous.filter(item=>item!==term)].slice(0,5));
  };
  const choose=(entry:Entry)=>{
    remember();
    setOpen(false);
    input.current?.blur();
    onOpen(scope.id,entry);
  };
  const clear=()=>{
    changeText('');
    setFilters({});
    setCustomFilters({});
    input.current?.focus();
  };
  const row=(entry:LibrarySearchResult,index?:number)=><button
    type="button"
    id={index===undefined?undefined:`${id}-item-${index}`}
    className="fms-search-result"
    role={index===undefined?undefined:'option'}
    aria-selected={index===undefined?undefined:index===activeIndex}
    tabIndex={index===undefined?0:-1}
    onClick={()=>choose(entry)}>
    <span className="fms-search-result-art">
      {entry.thumbnailUrl?<img src={entry.thumbnailUrl} alt="" loading="lazy" decoding="async"/>:<Icon icon={entry.kind==='folder'?Folder01Icon:File01Icon} size={20}/>}
    </span>
    <span className="fms-search-result-copy"><strong>{entry.name}</strong><small>{scope.name}{entry.parentName&&' / '+entry.parentName}</small></span>
  </button>;
  const recentGroup=(label:string,group:typeof folders,value:'file'|'folder')=>group.items.length>0&&<section className="fms-search-recent">
    <div><h2>{label}</h2>{group.cursor&&<Button size="sm" variant="ghost" onPress={()=>{setRecentHeight(panel.current?.offsetHeight??0);setKind(value);input.current?.focus();}}>{t.searchShowMore}</Button>}</div>
    {group.items.map(entry=><div key={entry.id}>{row(entry)}</div>)}
  </section>;
  const modePicker=hasIndex&&<div className="fms-search-mode-bar">
    <div className="fms-search-modes" role="group" aria-label={t.searchMode}>
      {modes.map(item=><Button key={item} size="sm" variant="ghost" className="fms-search-mode-trigger"
        aria-pressed={choice===item} onPress={()=>{setPreferredMode(item);setKind(undefined);}}>
        {labels[item]}
      </Button>)}
    </div>
  </div>;

  const content=<div ref={root} className={`fms-header-search fms-library-search${spotlight?' fms-library-search-spotlight':''}`} data-open={open||undefined}>
    <SearchField className="fms-search-primary" aria-label={t.searchLibrary} value={text} onChange={changeText}>
      <SearchField.Group>
        <SearchField.SearchIcon><Icon icon={Search01Icon} size={19}/></SearchField.SearchIcon>
        <SearchField.Input ref={input} maxLength={200} placeholder={choice==='semantic'?t.searchDescribe:choice==='index'?t.searchContentPlaceholder:t.searchPlaceholder} onFocus={()=>setOpen(true)}
          onClick={()=>setOpen(true)} aria-expanded={open} aria-controls={open?id:undefined}
          onKeyDownCapture={event=>{
            if(event.key!=='Escape'||!open||event.nativeEvent.isComposing)return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
          }}
          aria-activedescendant={open&&rows.some(item=>item.index===activeIndex)?`${id}-item-${activeIndex}`:undefined}
          onKeyDown={event=>{
            if(event.nativeEvent.isComposing)return;
            if(event.key==='Enter'){
              event.preventDefault();
              if(results.items[activeIndex])choose(results.items[activeIndex]);
              else if(!loadingResults&&results.items[0])choose(results.items[0]);
              return;
            }
            if(event.key!=='ArrowDown'&&event.key!=='ArrowUp')return;
            if(!results.items.length)return;
            event.preventDefault();
            const next=Math.max(0,Math.min(results.items.length-1,activeIndex+(event.key==='ArrowDown'?1:-1)));
            setActiveIndex(next);
            virtualizer.scrollToIndex(next);
          }}/>
        {spotlight&&<Button slot={null} isIconOnly variant="ghost" size="sm" className="fms-spotlight-close" aria-label={t.close} onPress={()=>setOpen(false)}><Icon icon={XIcon} size={20}/></Button>}
        {(text||!recent)&&<Button slot={null} size="sm" variant="ghost" className="fms-search-clear" onPress={clear}>{t.clear}</Button>}
        <Button slot={null} isIconOnly size="sm" variant="ghost" className="fms-search-filters-toggle" aria-label={t.filters} aria-expanded={open&&showFilters} {...(open?{'aria-controls':`${id}-filters`}:{})}
          onPress={()=>{setShowFilters(value=>!open||!value);setOpen(true);}}>
          <Icon icon={FilterHorizontalIcon} size={20}/>
        </Button>
      </SearchField.Group>
    </SearchField>
    {open&&<div ref={panel} className="fms-search-panel" role="region" aria-label={t.searchResults}
      style={kind&&results.loading&&!results.items.length&&recentHeight>0
        ? {minBlockSize: `min(${recentHeight}px, 68dvh)`}
        : undefined}>
      {modePicker}
      <div className="fms-search-filter-disclosure" id={`${id}-filters`} data-expanded={showFilters||undefined} inert={!showFilters} aria-hidden={!showFilters}>
        <div><div className="fms-search-filters">
            {multiple&&workspaces.length>0&&<Dropdown>
              <Button size="sm" variant="tertiary" className="fms-filter-shortcut" aria-label={t.workspaces}>
                <Icon icon={Folder01Icon} size={16}/>{scope.name}<Icon icon={ArrowDown01Icon} size={14}/>
              </Button>
              <Dropdown.Popover placement="bottom start" UNSTABLE_portalContainer={filterPortal??portal}>
                <Dropdown.Menu className="fms-search-workspaces" aria-label={t.workspaces} selectionMode="single" selectedKeys={[scope.id]} onSelectionChange={keys=>{
                  if(keys==='all')return;
                  const key=keys.values().next().value;
                  if(typeof key!=='string'||key===scope.id)return;
                  setScopeId(key);setFilters({});setCustomFilters({});setKind(undefined);
                }}>
                  {workspaces.map(item=><Dropdown.Item key={item.id} id={item.id} textValue={item.name}><Label>{item.name}</Label><Dropdown.ItemIndicator/></Dropdown.Item>)}
                </Dropdown.Menu>
              </Dropdown.Popover>
            </Dropdown>}
            {filterPortal&&<FilterItems key={scope.id} searchPanel uploaders={uploaders} filters={filters} customFilters={customFilters} extensions={extensions}
              transport={transport} workspace={scope.id} portal={filterPortal} t={t} apply={(next,custom)=>{setFilters(next);setCustomFilters(custom);}}/>}
        </div></div>
      </div>
      <div ref={setFilterPortal} className="fms-search-filter-portals" inert={!showFilters}/>
      {recent?<div id={id} className="fms-search-overview">
        {history.length>0&&<div className="fms-search-history">{history.map(value=><Button key={value} size="sm" variant="tertiary" onPress={()=>{changeText(value);input.current?.focus();}}><Icon icon={Search01Icon} size={15}/>{value}</Button>)}</div>}
        {recentGroup(t.searchRecentFolders,folders,'folder')}{recentGroup(t.searchRecentFiles,files,'file')}
        {(folders.loading||files.loading)&&<div className="fms-search-status" role="status"><Spinner size="sm" aria-hidden="true"/>{t.loading}</div>}
        {(folders.failed||files.failed)&&<Button size="sm" variant="ghost" onPress={()=>{if(folders.failed)folders.retry();if(files.failed)files.retry();}}>{t.retry}</Button>}
        {!folders.items.length&&!files.items.length&&!folders.loading&&!files.loading&&!folders.failed&&!files.failed&&<SearchEmpty recent title={t.searchEmptyRecent} description={t.searchEmptyRecentHint}/>}
      </div>:<>
        <div className="fms-search-result-heading"><strong>{kind==='folder'?t.searchRecentFolders:kind==='file'?t.searchRecentFiles:expand&&results.items.length?(hasSemantic?t.searchRelatedResults:t.searchContentResults):t.searchResults}</strong></div>
        <div className="fms-search-result-body">
          <div className="fms-search-results" ref={scroll} id={id} role="listbox" aria-label={t.searchResults} aria-busy={loadingResults}>
            <div className="fms-search-result-plane" style={{height:virtualizer.getTotalSize()}}>
              {rows.map(item=><div key={item.key} className="fms-search-virtual-row" style={{transform:`translateY(${item.start}px)`}}>{row(results.items[item.index]!,item.index)}</div>)}
            </div>
          </div>
          {loadingResults&&<div className={`fms-search-status${results.items.length?' is-paging':''}`} role="status"><Spinner size="sm" aria-hidden="true"/>{t.loading}</div>}
          {results.failed&&<div className="fms-search-status" role="status"><Button size="sm" variant="ghost" onPress={results.retry}>{t.retry}</Button></div>}
          {!results.items.length&&!loadingResults&&!results.failed&&!results.cursor&&<SearchEmpty title={mode==='exact'?t.searchNoMatches:t.searchNoResults} description={mode==='exact'?t.searchNoMatchesHint:t.searchNoContentHint}/>}
          {results.limited&&<p className="fms-search-status is-paging">{t.searchRefine}</p>}
        </div>
      </>}
    </div>}
  </div>;
  if(!spotlight)return content;
  return <Modal><Modal.Backdrop isOpen={open} isDismissable onOpenChange={setOpen} UNSTABLE_portalContainer={portal} className="fms-spotlight-backdrop">
    <Modal.Container className="fms-spotlight-container" placement="center"><Modal.Dialog className="fms-spotlight-dialog" aria-label={t.searchLibrary}><Modal.Heading className="sr-only">{t.searchLibrary}</Modal.Heading>{content}</Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
