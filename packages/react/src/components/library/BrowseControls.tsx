import {Button,Calendar,DateField,DatePicker,Dropdown,Input,Label,ListBox,Popover,Select,Separator,TextField} from '@heroui/react';
import {Add01Icon,ArrowDown01Icon,ArrowLeft01Icon,ArrowRight01Icon,Calendar03Icon,Cancel01Icon,FilterHorizontalIcon,Sorting01Icon} from '@hugeicons/core-free-icons';
import {useCallback,useLayoutEffect,useRef,useState} from 'react';
import {parseDate} from '@internationalized/date';
import type {BrowseDirection,BrowseExtensions,BrowseFilters,BrowseSort,StudioTransport,BrowseCategory} from '../../contracts';
import {Icon,type Messages} from '../../ui';
import {ChoiceFilter} from './ChoiceFilter';
import {CodeIcon,File01Icon,FileZipIcon,Image01Icon,Video01Icon,MusicNote01Icon,TextFontIcon,Presentation01Icon,FileSpreadsheetIcon} from '@hugeicons/core-free-icons';

const MEBIBYTE = 1024*1024;
const SORT_KEYS = ['name','updated','size','type'] as const;

type Props = {
  transport?: StudioTransport;
  workspace?: string;
  filters:BrowseFilters;
  customFilters:Record<string,string>;
  extensions:BrowseExtensions;
  sort:BrowseSort;
  direction:BrowseDirection;
  portal:HTMLDivElement;
  t:Messages;
  apply:(filters:BrowseFilters,custom:Record<string,string>)=>void;
  order:(sort:BrowseSort,direction:BrowseDirection)=>void;
};

export function BrowseControls(props:Props) {
  return <div className="fms-browse-controls">
    <FilterControls {...props}/>
    <SortControl {...props}/>
  </div>;
}

type FilterProps=Pick<Props,'filters'|'customFilters'|'extensions'|'portal'|'t'|'apply'|'transport'|'workspace'>&{uploaders?:boolean; searchPanel?:boolean};

/** Measure the actual translated chips against the toolbar, including its right controls. */
export function FilterControls(props:FilterProps) {
  const host=useRef<HTMLDivElement>(null),inline=useRef<HTMLDivElement>(null);
  const [compact,setCompact]=useState(false),[open,setOpen]=useState(false);
  const [nestedPortal,setNestedPortal]=useState<HTMLDivElement|null>(null);
  useLayoutEffect(()=>{
    const row=host.current?.closest('.fms-toolbar'),chips=inline.current;
    if(!row||!chips)return;
    const tools=row.querySelector('.fms-toolbar-view');
    const measure=()=>{
      const style=getComputedStyle(row),available=row.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight);
      setCompact(chips.scrollWidth+(tools?.getBoundingClientRect().width??0)+parseFloat(style.columnGap||'0')>available);
    };
    const observer=new ResizeObserver(measure);observer.observe(row);observer.observe(chips);if(tools)observer.observe(tools);measure();
    return()=>observer.disconnect();
  },[]);
  const count=(props.filters.types?.length??(props.filters.type?1:0))+(props.filters.extensions?.length??0)+(props.filters.tagIds?.length??0)+(props.filters.uploaderIds?.length??0)+(props.filters.tagLocale?1:0)+['dateFrom','dateTo','minBytes','maxBytes'].filter(key=>key in props.filters).length+Object.keys(props.customFilters).length;
  return <div ref={host} className="fms-responsive-filters" data-compact={compact||undefined}>
    <div ref={inline} className="fms-inline-filters" inert={compact} aria-hidden={compact||undefined}><FilterItems key={String(compact)} {...props}/></div>
    {compact&&<Popover isOpen={open} onOpenChange={setOpen}>
      <Button size="sm" variant="tertiary" className="fms-filter-shortcut" aria-label={props.t.filters}><Icon icon={FilterHorizontalIcon} size={16}/>{props.t.filters}{count>0&&<span className="fms-filter-count">{count}</span>}<Icon icon={ArrowDown01Icon} size={14}/></Button>
      <Popover.Content placement="bottom start" UNSTABLE_portalContainer={props.portal}>
        <Popover.Dialog aria-label={props.t.filters} className="fms-filter-hub"><div className="fms-filter-hub-options" ref={setNestedPortal}>
          {open&&nestedPortal&&<FilterItems {...props} portal={nestedPortal}/>}
        </div></Popover.Dialog>
      </Popover.Content>
    </Popover>}
  </div>;
}

export function FilterItems({filters,customFilters,extensions,portal,t,apply,transport,workspace,uploaders,searchPanel}:FilterProps) {
  const [open,setOpen] = useState(false);
  const listMembers=transport?.members;
  const memberChoices=useCallback<NonNullable<StudioTransport['browseChoices']>>(async (scope,_kind,options,signal)=>{
    if(!listMembers)return {items:[],nextCursor:null,hasMore:false};
    const page=await listMembers(scope,options.cursor??null,signal,options.search);
    return {...page,items:page.items.flatMap(member=>member.name?.trim()?[{id:member.id,label:member.name}]:[])};
  },[listMembers]);
  const count = ['dateFrom','dateTo','minBytes','maxBytes'].filter(key=>key in filters).length+Object.keys(customFilters).length;
  const categories: {id:BrowseCategory;label:string;icon:typeof CodeIcon}[] = [
    {id:'code',label:t.codeFiles,icon:CodeIcon},{id:'document',label:t.documents,icon:File01Icon},
    {id:'spreadsheet',label:t.spreadsheets,icon:FileSpreadsheetIcon},{id:'presentation',label:t.presentations,icon:Presentation01Icon},
    {id:'image',label:t.images,icon:Image01Icon},{id:'video',label:t.videos,icon:Video01Icon},{id:'audio',label:t.audio,icon:MusicNote01Icon},
    {id:'archive',label:t.archives,icon:FileZipIcon},{id:'font',label:t.fonts,icon:TextFontIcon},{id:'other',label:t.otherFiles,icon:File01Icon},
  ];
  const replace = (key:'types'|'extensions'|'tagIds'|'uploaderIds',values:string[])=>{
    const next={...filters};if(key==='types')delete next.type;
    if(values.length)Object.assign(next,{[key]:values});else delete next[key];
    apply(next,customFilters);
  };
  const [moreOpen,setMoreOpen]=useState(false);
  const [morePortal,setMorePortal]=useState<HTMLDivElement|null>(null);
  const fileTypes = <ChoiceFilter label={searchPanel?t.searchFileTypes:t.fileType} selected={filters.types??(filters.type?[filters.type==='pdf'?'document':filters.type]:[])} options={categories}
      onChange={values=>replace('types',values)} portal={portal} t={t}/>;
  const extensionFilter = (target:HTMLDivElement) => transport?.browseChoices && <ChoiceFilter label={t.extension} selected={filters.extensions??[]} source={transport.browseChoices} workspace={workspace} kind="extensions"
        onChange={values=>replace('extensions',values)} portal={target} t={t}/>;
  const tags = transport?.browseChoices && <ChoiceFilter label={t.tags} selected={filters.tagIds??[]} source={transport.browseChoices} workspace={workspace} kind="tags" locale={filters.tagLocale}
        matchAll={filters.tagMatch==='all'} onMatchAll={all=>{const next={...filters};if(all)next.tagMatch='all';else delete next.tagMatch;apply(next,customFilters);}}
        onChange={values=>replace('tagIds',values)} portal={portal} t={t}/>;
  const language = transport?.browseChoices && <ChoiceFilter label={t.tagLocale} selected={filters.tagLocale?[filters.tagLocale]:[]} source={transport.browseChoices} workspace={workspace} kind="tagLocales" multiple={false}
        onChange={values=>{const next={...filters};if(values[0])next.tagLocale=values[0];else delete next.tagLocale;apply(next,customFilters);}} portal={portal} t={t}/>;
  const uploader = uploaders&&listMembers&&<ChoiceFilter label={t.searchUploadedBy} selected={filters.uploaderIds??[]} source={memberChoices} workspace={workspace} kind="extensions"
      onChange={values=>replace('uploaderIds',values)} portal={portal} t={t}/>;
  const dateAndSize = (target:HTMLDivElement) => <Popover isOpen={open} onOpenChange={setOpen}>
      <Button size="sm" variant="tertiary" className="fms-filter-shortcut" aria-label={t.dateAndSize}>
        <Icon icon={FilterHorizontalIcon} size={16}/>{t.dateAndSize}{count>0&&<span className="fms-filter-count">{count}</span>}
        <Icon icon={ArrowDown01Icon} size={14}/>
      </Button>
      <Popover.Content placement="bottom end" UNSTABLE_portalContainer={target}>
        <Popover.Dialog aria-label={t.dateAndSize} className="fms-filter-dialog">
          {open&&<FilterForm filters={filters} customFilters={customFilters} extensions={extensions} portal={target} t={t} submit={(value,custom)=>{apply(value,custom);setOpen(false);}}/>}
        </Popover.Dialog>
      </Popover.Content>
    </Popover>;
  if(searchPanel)return <>
    {uploader}{tags}{fileTypes}
    <Popover isOpen={moreOpen} onOpenChange={setMoreOpen}>
      <Button size="sm" variant="tertiary" className="fms-filter-shortcut"><Icon icon={Add01Icon} size={16}/>{t.searchMoreFilters}{count+(filters.extensions?.length??0)>0&&<span className="fms-filter-count">{count+(filters.extensions?.length??0)}</span>}</Button>
      <Popover.Content placement="bottom start" UNSTABLE_portalContainer={portal}>
        <Popover.Dialog aria-label={t.searchMoreFilters} className="fms-search-extra-filters">
          <div ref={setMorePortal}>{moreOpen&&morePortal&&<>{extensionFilter(morePortal)}{dateAndSize(morePortal)}</>}</div>
        </Popover.Dialog>
      </Popover.Content>
    </Popover>
  </>;
  return <>{fileTypes}{extensionFilter(portal)}{tags}{!uploaders&&language}{uploader}{dateAndSize(portal)}</>;
}

export function SortControl({extensions,sort,direction,portal,t,order}:Pick<Props,'extensions'|'sort'|'direction'|'portal'|'t'|'order'>) {
  const sortLabels:Record<string,string> = {name:t.sortName,updated:t.sortUpdated,size:t.sortSize,type:t.sortType};
  for(const option of extensions.sorters??[])sortLabels[option.id]=option.label;
  const sortKeys = [...SORT_KEYS,...(extensions.sorters??[]).map(option=>option.id)];
  return <Dropdown>
      <Button size="sm" variant="tertiary" aria-label={`${t.sortBy}: ${sortLabels[sort]}, ${direction==='asc'?t.ascending:t.descending}`}>
        <Icon icon={Sorting01Icon} size={16}/><span className="fms-sort-prefix">{t.sortBy}</span><strong>{sortLabels[sort]}</strong><Icon icon={ArrowDown01Icon} size={14}/>
      </Button>
      <Dropdown.Popover className="fms-library-choice-menu" placement="bottom end" UNSTABLE_portalContainer={portal}>
        <Dropdown.Menu aria-label={t.sortBy} onAction={key=>{
          if(key==='fms:direction:asc'||key==='fms:direction:desc'){order(sort,key==='fms:direction:asc'?'asc':'desc');return;}
          const next = sortKeys.find(value=>value===key);
          if(next){order(next,next===sort&&direction==='asc'?'desc':'asc');}
        }}>
          <Dropdown.Section selectionMode="single" selectedKeys={[sort]}>
          {sortKeys.map(key=><Dropdown.Item className="ps-2 pe-7" key={key} id={key} textValue={sortLabels[key]??key}>
            <Label>{sortLabels[key]}</Label>
            <Dropdown.ItemIndicator className="start-auto end-2"/>
          </Dropdown.Item>)}
          </Dropdown.Section>
          <Separator/>
          <Dropdown.Section selectionMode="single" selectedKeys={['fms:direction:'+direction]}>
            <Dropdown.Item className="ps-2 pe-7" id="fms:direction:asc" textValue={t.ascending}><Label>{t.ascending}</Label><Dropdown.ItemIndicator className="start-auto end-2"/></Dropdown.Item>
            <Dropdown.Item className="ps-2 pe-7" id="fms:direction:desc" textValue={t.descending}><Label>{t.descending}</Label><Dropdown.ItemIndicator className="start-auto end-2"/></Dropdown.Item>
          </Dropdown.Section>
        </Dropdown.Menu>
      </Dropdown.Popover>
    </Dropdown>;
}

function FilterDate({label,value,onChange,portal,t}:{label:string;value:string;onChange:(value:string)=>void;portal:HTMLElement;t:Messages}) {
  return <DatePicker className="fms-filter-date" value={value?parseDate(value):null} onChange={date=>onChange(date?.toString()??'')} granularity="day" shouldForceLeadingZeros>
    <Label>{label}</Label>
    <DateField.Group variant="secondary">
      <DateField.Input>{segment=><DateField.Segment segment={segment}/>}</DateField.Input>
      <DateField.Suffix>
        {value&&<Button slot={null} isIconOnly size="sm" variant="ghost" aria-label={`${t.clearDate}: ${label}`} onPress={()=>onChange('')}><Icon icon={Cancel01Icon} size={14}/></Button>}
        <DatePicker.Trigger aria-label={t.chooseDate}><DatePicker.TriggerIndicator><Icon icon={Calendar03Icon} size={18}/></DatePicker.TriggerIndicator></DatePicker.Trigger>
      </DateField.Suffix>
    </DateField.Group>
    <DatePicker.Popover UNSTABLE_portalContainer={portal} placement="bottom start">
      <Calendar aria-label={`${t.chooseDate}: ${label}`}>
        <Calendar.Header>
          <Calendar.Heading/>
          <Calendar.NavButton slot="previous"><Icon icon={ArrowLeft01Icon} size={18}/></Calendar.NavButton>
          <Calendar.NavButton slot="next"><Icon icon={ArrowRight01Icon} size={18}/></Calendar.NavButton>
        </Calendar.Header>
        <Calendar.Grid>
          <Calendar.GridHeader>{day=><Calendar.HeaderCell>{day}</Calendar.HeaderCell>}</Calendar.GridHeader>
          <Calendar.GridBody>{date=><Calendar.Cell date={date}/>}</Calendar.GridBody>
        </Calendar.Grid>
      </Calendar>
    </DatePicker.Popover>
  </DatePicker>;
}

function FilterForm({filters,customFilters,extensions,portal,t,submit}:{filters:BrowseFilters;customFilters:Record<string,string>;extensions:BrowseExtensions;portal:HTMLDivElement;t:Messages;submit:(value:BrowseFilters,custom:Record<string,string>)=>void}) {
  const [dateFrom,setDateFrom] = useState(filters.dateFrom??'');
  const [dateTo,setDateTo] = useState(filters.dateTo??'');
  const [minimum,setMinimum] = useState(filters.minBytes===undefined?'':String(filters.minBytes/MEBIBYTE));
  const [maximum,setMaximum] = useState(filters.maxBytes===undefined?'':String(filters.maxBytes/MEBIBYTE));
  const [custom,setCustom] = useState(customFilters);
  const [invalid,setInvalid] = useState(false);
  const [form,setForm] = useState<HTMLFormElement|null>(null);

  const chooseDates = (days:number)=>{
    const today = new Date();
    const start = new Date(today);
    start.setUTCDate(start.getUTCDate()-days+1);
    setDateFrom(start.toISOString().slice(0,10));
    setDateTo(today.toISOString().slice(0,10));
  };

  return <form ref={setForm} className="fms-filter-form" onSubmit={event=>{
    event.preventDefault();
    const minBytes = minimum===''?undefined:Math.round(Number(minimum)*MEBIBYTE);
    const maxBytes = maximum===''?undefined:Math.round(Number(maximum)*MEBIBYTE);
    const invalidSize = [minBytes,maxBytes].some(value=>value!==undefined&&(!Number.isSafeInteger(value)||value<0));
    if(invalidSize||(dateFrom&&dateTo&&dateFrom>dateTo)||(minBytes!==undefined&&maxBytes!==undefined&&minBytes>maxBytes)){
      setInvalid(true);
      return;
    }
    const next={...filters};
    delete next.dateFrom;delete next.dateTo;delete next.minBytes;delete next.maxBytes;
    submit({
      ...next,
      ...(dateFrom?{dateFrom}:{}),
      ...(dateTo?{dateTo}:{}),
      ...(minBytes!==undefined?{minBytes}:{}),
      ...(maxBytes!==undefined?{maxBytes}:{}),
    },custom);
  }}>
    <div className="fms-filter-presets">
      <Button size="sm" variant="tertiary" onPress={()=>chooseDates(7)}>{t.last7}</Button>
      <Button size="sm" variant="tertiary" onPress={()=>chooseDates(30)}>{t.last30}</Button>
    </div>
    <div className="fms-filter-range">
      <FilterDate label={t.dateFrom} value={dateFrom} onChange={setDateFrom} portal={form??portal} t={t}/>
      <FilterDate label={t.dateTo} value={dateTo} onChange={setDateTo} portal={form??portal} t={t}/>
      <TextField value={minimum} onChange={setMinimum}><Label>{t.minBytes}</Label><Input variant="secondary" type="number" min={0} step="any"/></TextField>
      <TextField value={maximum} onChange={setMaximum}><Label>{t.maxBytes}</Label><Input variant="secondary" type="number" min={0} step="any"/></TextField>
    </div>
    {(extensions.filters??[]).map(filter=><Select variant="secondary" key={filter.id}
      value={custom[filter.id]??null} onChange={value=>setCustom(previous=>{
        const next={...previous};
        if(value===null)delete next[filter.id];
        else next[filter.id]=String(value);
        return next;
      })}>
      <Label>{filter.label}</Label>
      <Select.Trigger><Select.Value/><Select.Indicator/></Select.Trigger>
      <Select.Popover UNSTABLE_portalContainer={form??portal}><ListBox>
        {filter.options.map(option=><ListBox.Item key={option.id} id={option.id} textValue={option.label}>
          <Label>{option.label}</Label><ListBox.ItemIndicator/>
        </ListBox.Item>)}
      </ListBox></Select.Popover>
    </Select>)}
    {invalid&&<p className="fms-error" role="alert">{t.invalidFilters}</p>}
    <div className="fms-filter-actions">
      <Button size="sm" variant="ghost" onPress={()=>submit({},{})}>{t.clearFilters}</Button>
      <Button size="sm" type="submit">{t.applyFilters}</Button>
    </div>
  </form>;
}
