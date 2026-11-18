import {Button} from '@heroui/react';
import {useEffect, useRef, useState} from 'react';
import {useIsPresent} from 'motion/react';
import type {Entry} from '../../contracts';
import type {Messages} from '../../ui';
import {officePreviewFormats,type OfficePreviewMount,type OfficePreviewSettings,type OfficePreviewFormat,type OfficeFindState,type OfficeFindResult,type OfficePreviewHandle,type OfficeViewportControl,type OfficeViewportState} from '../../office-preview-contract';

type Reader = typeof import('../../office-preview');
let engine: Promise<Reader> | undefined;
function loadReader(): Promise<Reader> {
  return engine ??= import(new URL('./office-preview.js', import.meta.url).href).catch(reason => {engine = undefined; throw reason;});
}
const mimeFormats: Record<string, OfficePreviewFormat> = {
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'docx',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'xlsx',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation':'pptx',
  'application/msword':'doc','application/vnd.ms-excel':'xls','application/vnd.ms-powerpoint':'ppt',
  'application/vnd.oasis.opendocument.text':'odt','application/vnd.oasis.opendocument.spreadsheet':'ods','application/vnd.oasis.opendocument.presentation':'odp',
  'application/vnd.apple.pages':'pages','application/vnd.apple.keynote':'key','application/vnd.apple.numbers':'numbers',
  'application/rtf':'rtf','text/rtf':'rtf','text/csv':'csv','text/markdown':'md','text/plain':'txt',
};
function officeFormat(entry: Entry): OfficePreviewFormat | undefined {
  const extension = entry.name.split('.').at(-1)?.toLowerCase();
  const mime=entry.mimeType?.toLowerCase().split(';')[0]?.trim()??'';
  const namedFormat=officePreviewFormats.find(value=>value===extension);
  if(namedFormat)return namedFormat;
  if(mime==='text/plain'&&entry.name.includes('.'))return undefined;
  return mimeFormats[mime];
}
export function supportsOfficePreview(entry: Entry, settings: false | OfficePreviewSettings = {}) {
  const format = officeFormat(entry);
  return settings !== false && format !== undefined && (settings.formats ?? officePreviewFormats).includes(format);
}
export function supportsOfficePages(entry:Entry,settings:false|OfficePreviewSettings={}) {return supportsOfficePreview(entry,settings)&&!['xlsx','xls','ods','csv','numbers'].includes(officeFormat(entry)??'');}
export function supportsOfficeSearch(entry:Entry,settings:false|OfficePreviewSettings={}) {
  return settings!==false && settings.search!==false && supportsOfficePreview(entry,settings) && !['pptx','ppt','key','odp'].includes(officeFormat(entry)??'');
}
const emptyViewport:OfficeViewportControl={};
const closedFind:OfficeFindState={open:false,query:'',step:0,direction:1};
export function OfficePreview({entry, url, t, active = true, settings = {}, theme = 'light', search=closedFind,onSearchResult,viewport=emptyViewport,onViewport,onPrintReady}: {
  onPrintReady?:(print:(()=>Promise<void>)|undefined)=>void;
  entry: Entry; url: string; t: Messages; active?: boolean;
  settings?: OfficePreviewSettings; theme?: 'light' | 'dark';
  viewport?:OfficeViewportControl|undefined;onViewport?:(state:OfficeViewportState)=>void;
  search?:OfficeFindState|undefined;onSearchResult?:(result:OfficeFindResult)=>void;
}) {
  const printCallback=useRef(onPrintReady);printCallback.current=onPrintReady;
  const readerHandle=useRef<OfficePreviewHandle|undefined>(undefined);
  const latestViewport=useRef(viewport);latestViewport.current=viewport;
  const viewportCallback=useRef(onViewport);viewportCallback.current=onViewport;
  useEffect(()=>{readerHandle.current?.viewport(viewport);},[viewport]);
  const latestFind=useRef(search);latestFind.current=search;
  const latestResult=useRef(onSearchResult);latestResult.current=onSearchResult;
  useEffect(()=>{readerHandle.current?.search(search);},[search]);
  const host = useRef<HTMLDivElement>(null);
  const bar = useRef<HTMLDivElement>(null);
  const present = useIsPresent();
  const [state, setState] = useState<'loading' | 'ready' | 'failed' | 'large'>('loading');
  const [attempt, setAttempt] = useState(0);
  const maxBytes = settings.maxBytes ?? 50 * 1024 * 1024;
  const timeoutMs = Math.max(1000, settings.timeoutMs ?? 60_000);
  const residentCells = Math.max(1000, settings.residentCells ?? 250_000);
  const pageRows = Math.max(1, settings.pageRows ?? 512);
  const format = officeFormat(entry);
  const name = entry.name.toLowerCase().endsWith('.'+format) ? entry.name : entry.name+'.'+format;
  useEffect(() => {
    const element = host.current;
    if (!element || !present || !active) return;
    if (entry.size === null || entry.size > maxBytes) {setState('large'); return;}
    const shadow = element.shadowRoot ?? element.attachShadow({mode: 'open'});
    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = new URL('./office-preview.css', import.meta.url).href;
    const mount = document.createElement('div');
    mount.className = 'fms-office-reader';
    shadow.replaceChildren(stylesheet, mount);
    let disposed = false;
    let stopped = false;
    let destroy: (() => void) | undefined;
    let frame = 0;
    let phase = '';
    let progress: number | undefined;
    setState('loading');printCallback.current?.(undefined);
    const updateBar = (value: number | undefined) => {
      progress = value;
      if (frame) return;
      frame = requestAnimationFrame(() => {
        frame = 0;
        const track = bar.current;
        if (!track) return;
        track.dataset.indeterminate = String(progress === undefined);
        if (progress === undefined) track.removeAttribute('aria-valuenow');
        else track.setAttribute('aria-valuenow', String(Math.floor(progress * 100)));
        track.style.setProperty('--fms-document-progress', String(progress ?? 0));
      });
    };
    updateBar(undefined);
    const fail = () => {if (!disposed && !stopped) {stopped = true;clearTimeout(timer);destroy?.();destroy = undefined;setState('failed');}};
    const timer = window.setTimeout(fail, timeoutMs);
    const cssReady = new Promise<void>((resolve, reject) => {
      stylesheet.onload = () => resolve(); stylesheet.onerror = () => reject(new Error('Stylesheet unavailable'));
    });
    void Promise.all([loadReader(), cssReady]).then(([reader]) => {
      if (disposed || stopped) return;
      const options: OfficePreviewMount = {
        url, name, theme, residentCells, pageRows,floatingZoom:settings.zoomControls==='floating',viewport:latestViewport.current,
        onViewport:state=>{if(!disposed&&!stopped)viewportCallback.current?.(state);},outline:settings.outline===true,search:latestFind.current,
        onSearchResult:result=>{if(!disposed&&!stopped)latestResult.current?.(result);},
        onProgress: value => {
          if (disposed || stopped) return;
          if (phase !== value.phase) {phase = value.phase;updateBar(undefined);}
          updateBar(value.total > 0 ? Math.min(.98, value.loaded / value.total) : undefined);
        },
        onReady: () => {if (!disposed && !stopped) {clearTimeout(timer);updateBar(1);setState('ready');printCallback.current?.(()=>readerHandle.current?.print()??Promise.reject(new Error('Preview closed')));}},
        onError: fail,
      };
      const handle=reader.mountOfficePreview(mount, options);
      readerHandle.current=handle;
      destroy=()=>{handle.destroy();if(readerHandle.current===handle)readerHandle.current=undefined;};
    }).catch(fail);
    return () => {
      disposed = true;printCallback.current?.(undefined);
      clearTimeout(timer);cancelAnimationFrame(frame);
      stylesheet.onload = stylesheet.onerror = null;
      destroy?.();shadow.replaceChildren();
    };
  }, [url, name, entry.size, active, present, attempt, maxBytes, timeoutMs, residentCells, pageRows, theme, t, settings.outline, settings.zoomControls]);
  return <div className="fms-office-preview" aria-busy={state === 'loading'}>
    <div ref={bar} className="fms-document-progress" role="progressbar" aria-label={t.loadingDocument}
      aria-valuemin={0} aria-valuemax={100} aria-hidden={state === 'ready'} data-complete={state === 'ready'} hidden={state === 'failed' || state === 'large'}><span/></div>
    <div className="fms-office-host" ref={host}/>
    {state === 'loading' && <span className="fms-document-status" role="status">{t.loadingDocument}</span>}
    {(state === 'failed' || state === 'large') && <div className="fms-document-status" role="alert">
      <p>{state === 'large' ? t.documentPreviewTooLarge : t.previewFailed}</p>
      {state === 'failed' && <Button size="sm" variant="secondary" onPress={() => setAttempt(value => value + 1)}>{t.retry}</Button>}
    </div>}
  </div>;
}
