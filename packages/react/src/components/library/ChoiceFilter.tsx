import {Button, Checkbox, Label, ListBox, ListBoxLoadMoreItem, ListLayout, Virtualizer, Collection, Popover, SearchField, Spinner} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {ArrowDown01Icon, Search01Icon, type Folder01Icon} from '@hugeicons/core-free-icons';
import {useLayoutEffect, useRef, useState} from 'react';
import type {BrowseChoice, StudioTransport} from '../../contracts';
import {Icon, errorText, type Messages} from '../../ui';

const extensionLayout={rowSize:32,loaderSize:32,gap:4};

type Option = BrowseChoice & {icon?: typeof Folder01Icon};
type Props = {
  label: string;
  selected: string[];
  options?: Option[];
  source?: StudioTransport['browseChoices'] | undefined;
  workspace?: string | undefined;
  kind?: 'extensions' | 'tags' | 'tagLocales';
  locale?: string | undefined;
  multiple?: boolean;
  searchable?: boolean;
  matchAll?: boolean | undefined;
  onMatchAll?: ((value: boolean) => void) | undefined;
  onChange: (selected: string[]) => void;
  portal: HTMLDivElement;
  t: Messages;
};

// Remote searches are cursor-paged and aborted on close/scope changes.
// Extension rows are virtualized; cached options are bounded independently of the DOM.
export function ChoiceFilter({label, selected, options, source, workspace, kind, locale, multiple=true, searchable=true, matchAll, onMatchAll, onChange, portal, t}: Props) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [items, setItems] = useState<Option[]>([]);
  const [next, setNext] = useState<string|null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [attempt, retry] = useState(0);
  const request = useRef<AbortController|null>(null);
  const busy = useRef(false);
  const virtual=kind==='extensions';
  const limit=virtual?2048:120;
  const cursors=useRef(new Set<string>());
  useLayoutEffect(() => {
    request.current?.abort();
    busy.current = false;
    if(!open || options || !source || !workspace || !kind) return;
    const controller = new AbortController();
    request.current = controller;
    setItems([]);setNext(null);setLoading(true);setError('');cursors.current.clear();
    const timer=setTimeout(()=>{
      busy.current=true;
      void source(workspace,kind,{...(search?{search}:{}),...(locale?{locale}:{})},controller.signal).then(page=>{
        if(controller.signal.aborted)return;
        setItems(page.items.slice(0,60));setNext(page.nextCursor);
      }).catch(error=>{if(!controller.signal.aborted)setError(errorText(error));}).finally(()=>{
        if(!controller.signal.aborted){busy.current=false;setLoading(false);}
      });
    },search?200:0);
    return()=>{clearTimeout(timer);request.current?.abort();};
  },[open,options,source,workspace,kind,locale,search,attempt]);
  const more = () => {
    if(!open||busy.current||!source||!workspace||!kind||!next||items.length>=limit||cursors.current.has(next))return;
    cursors.current.add(next);
    const controller=new AbortController();request.current=controller;busy.current=true;setLoading(true);setError('');
    void source(workspace,kind,{cursor:next,...(search?{search}:{}),...(locale?{locale}:{})},controller.signal).then(page=>{
      if(controller.signal.aborted)return;
      setItems(previous=>{const map=new Map(previous.map(item=>[item.id,item]));for(const item of page.items)map.set(item.id,item);return [...map.values()].slice(0,limit);});
      setNext(page.nextCursor===next?null:page.nextCursor);
    }).catch(error=>{if(!controller.signal.aborted)setError(errorText(error));}).finally(()=>{if(!controller.signal.aborted){busy.current=false;setLoading(false);}});
  };
  const choices=options??items;
  const selectedVisible = choices.length>0 && choices.every(item=>selected.includes(item.id));
  const toggleShown=()=>{
    const merged=new Set(selected);
    if(selectedVisible) choices.forEach(item=>merged.delete(item.id));
    else choices.forEach(item=>{if(merged.size<32)merged.add(item.id);});
    onChange([...merged]);
  };
  const hasMore=!!next&&items.length<limit&&!error;
  const rowCount=choices.length+(hasMore?1:0);
  const listHeight=Math.max(0,rowCount*(extensionLayout.rowSize+extensionLayout.gap)-extensionLayout.gap);
  const list=<ListBox aria-label={label} style={virtual?{height:`min(${listHeight}px, 304px, 45dvh)`}:{}} escapeKeyBehavior="none" className={`fms-choice-options${virtual?' is-virtual':''}`} selectionMode={multiple?'multiple':'single'} selectedKeys={selected}
    disabledKeys={multiple&&selected.length>=32?choices.filter(item=>!selected.includes(item.id)).map(item=>item.id):[]}
    onSelectionChange={keys=>{if(keys!=='all'){
      // Virtual rows can be absent from the current collection after a server search.
      const visibleIds=new Set(choices.map(item=>item.id));
      const retained=multiple?selected.filter(id=>!visibleIds.has(id)):[];
      onChange([...new Set([...retained,...[...keys].map(String)])].slice(0,32));
    }}}>
    <Collection items={choices}>{item=><ListBox.Item id={item.id} textValue={item.label}>
      <ListBox.ItemIndicator/><Label>{item.label}</Label>{item.icon&&<Icon icon={item.icon} size={20}/>}
    </ListBox.Item>}</Collection>
    {virtual&&hasMore&&<ListBoxLoadMoreItem isLoading={loading} onLoadMore={more} scrollOffset={0.5} className="fms-choice-loader">{loading&&<Spinner size="sm" aria-label={t.loading}/>}</ListBoxLoadMoreItem>}
  </ListBox>;
  const changeOpen=(value:boolean)=>{
    setOpen(value);
    if(value)return;
    request.current?.abort();
    setSearch('');
  };
  return <Popover isOpen={open} onOpenChange={changeOpen}>
    <Button size="sm" variant="tertiary" className="fms-filter-shortcut" aria-label={label}>
      {label}{selected.length>0&&<span className="fms-filter-count">{selected.length}</span>}<Icon icon={ArrowDown01Icon} size={14}/>
    </Button>
    <Popover.Content placement="bottom start" UNSTABLE_portalContainer={portal}>
      <Popover.Dialog aria-label={label} className="fms-choice-surface"><div className="fms-choice-dialog">
        {!options&&searchable&&<SearchField variant="secondary" aria-label={`${t.searchChoices}: ${label}`} value={search} onChange={setSearch}>
          <SearchField.Group><SearchField.SearchIcon><HugeiconsIcon icon={Search01Icon} strokeWidth={2} size={20}/></SearchField.SearchIcon><SearchField.Input placeholder={kind==='tags'?t.addTag:t.search}/><SearchField.ClearButton aria-label={t.clearSearch}/></SearchField.Group>
        </SearchField>}
        <div className="fms-choice-heading"><span>{t.filterBy}</span>
          {multiple?<Button size="sm" variant="ghost" isDisabled={choices.length===0} onPress={toggleShown}>{selectedVisible?t.clearShown:next?t.selectShown:t.selectAll}</Button>:<Button size="sm" variant="ghost" isDisabled={selected.length===0} onPress={()=>onChange([])}>{t.clear}</Button>}
        </div>
        {onMatchAll&&<Checkbox variant="secondary" isSelected={matchAll??false} onChange={onMatchAll} className="fms-match-tags"><Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control><Label>{t.matchAllTags}</Label></Checkbox.Content></Checkbox>}
        {virtual?<Virtualizer layout={ListLayout} layoutOptions={extensionLayout}>{list}</Virtualizer>:list}
        {loading&&(!virtual||!next)&&<div className="fms-choice-status" role="status"><Spinner size="sm"/>{t.loading}</div>}
        {!loading&&!error&&choices.length===0&&!next&&<p className="fms-choice-status">{kind==='tags'?t.noTags:t.noChoices}</p>}
        {error&&<div className="fms-choice-status fms-error" role="alert">{error}<Button size="sm" variant="tertiary" onPress={()=>retry(n=>n+1)}>{t.retry}</Button></div>}
        {!virtual&&next&&items.length<limit&&<Button size="sm" variant="ghost" aria-disabled={loading} onPress={more}>{t.moreChoices}</Button>}
        {next&&items.length>=limit&&<p className="fms-choice-status">{t.refineSearch}</p>}
        {selected.length>=32&&<p className="fms-choice-status">{t.selectionLimit}</p>}
        {multiple&&<Button size="sm" variant="ghost" onPress={()=>onChange([])}>{t.clearSelection}</Button>}
      </div></Popover.Dialog>
    </Popover.Content>
  </Popover>;
}
