import {loadStudioLocale,resolveStudioLocale,StudioLocalizationProvider,type LoadedStudioLocale} from './workspace-i18n';
import {createPortal} from 'react-dom';
import {Spinner, Button} from '@heroui/react';
import {useCallback, useEffect, useLayoutEffect, useRef, useState} from 'react';
import type {StudioOptions} from './contracts';
import {StudioApp} from './StudioApp';
import {enableStudioInteractions} from './interaction-support';

export function MediaStudio(options: StudioOptions) {
  const host = useRef<HTMLDivElement>(null);
  const [container, setContainer] = useState<HTMLDivElement | null>(null);
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>('loading');
  const [ready, setReady] = useState(false);
  const [attempt, retry] = useState(0);
  const externalStartup = options.startupElement;
  const preferenceKey='flex-media-studio.preferences.'+(options.user?.email??'browser');
  const [requestedLocale,setRequestedLocale]=useState(()=>{let saved:unknown;try{saved=JSON.parse(localStorage.getItem(preferenceKey)??'{}').locale;}catch{}return resolveStudioLocale(saved,options.locale,document.documentElement.lang,...navigator.languages);});
  const [catalog,setCatalog]=useState<LoadedStudioLocale|null>(null),[localePending,setLocalePending]=useState(false),[localeError,setLocaleError]=useState(false);
  useEffect(()=>{let disposed=false;setLocalePending(true);setLocaleError(false);void loadStudioLocale(requestedLocale,options.localization).then(value=>{if(!disposed){setCatalog(value);setLocalePending(false);}}).catch(()=>{if(!disposed){setLocaleError(true);setLocalePending(false);}});return()=>{disposed=true;};},[requestedLocale,options.localization,attempt]);
  const requestLocale=useCallback((language:string)=>{const next=resolveStudioLocale(language);setRequestedLocale(next);try{const previous=JSON.parse(localStorage.getItem(preferenceKey)??'{}');localStorage.setItem(preferenceKey,JSON.stringify({...previous,locale:next}));}catch{}},[preferenceKey]);
  const lastOption=useRef(options.locale);
  useEffect(()=>{if(lastOption.current!==options.locale){lastOption.current=options.locale;setRequestedLocale(resolveStudioLocale(options.locale,document.documentElement.lang,...navigator.languages));}},[options.locale]);
  const reveal = useCallback(() => setReady(true), []);
  useEffect(() => () => externalStartup?.remove(), [externalStartup]);
  useLayoutEffect(() => {
    if (ready || status === 'failed' || localeError) externalStartup?.remove();
  }, [externalStartup, ready, status,localeError]);
  useEffect(() => {
    if (!host.current) return;
    enableStudioInteractions();
    setReady(false);
    setStatus('loading');
    const shadow = host.current.shadowRoot ?? host.current.attachShadow({mode: 'open'});
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = options.stylesheetUrl ?? new URL('./studio.css', import.meta.url).href;
    const root = document.createElement('div');
    root.className = `fms-root ${options.theme === 'dark' ? 'dark' : 'light'}`;
    // The slot keeps the initial status accessible even before the CSS request
    // completes. It is removed atomically when the styled application mounts.
    const slot = document.createElement('slot');
    slot.name = 'startup';
    link.onload = () => {slot.remove(); setContainer(root); setStatus('ready');};
    link.onerror = () => setStatus('failed');
    shadow.append(link, root, slot);
    return () => {link.onload = null; link.onerror = null; link.remove(); root.remove(); slot.remove(); setContainer(null);};
  }, [options.stylesheetUrl, attempt]);
  const label = catalog?.messages.loading??options.localization?.bootstrap?.loading;
  const retryLabel = catalog?.messages.retry??options.localization?.bootstrap?.retry;
  const picker = options.mode === 'picker';
  return <div className="fms-host" ref={host} data-startup={status} style={{height: '100%', minHeight: 440, position: 'relative'}}>
    {(status !== 'ready'||localeError&&!catalog) && (!externalStartup?.isConnected || status === 'failed'||localeError) && <div slot="startup" role="status" style={{height:'100%',display:'grid',placeContent:'center',fontFamily:'system-ui',fontSize:14}}>
      {status === 'failed'||localeError ? <Button aria-label={retryLabel??'↻'} onPress={() => retry(n => n + 1)}>{retryLabel??'↻'}</Button> : label??<Spinner/>}
    </div>}
    {container && createPortal(<>
      {!externalStartup?.isConnected && !ready && (picker
        ? <div className="fms-startup fms-picker-startup" role="status"><Spinner/><span>{label}</span></div>
        : <div className="fms-startup" role="status" aria-label={label}>
          <span className="fms-startup-spinner" aria-hidden="true"/>
        </div>)}
      {localeError&&catalog&&<div className="fms-locale-retry" role="alert"><Button variant="secondary" onPress={()=>retry(value=>value+1)}>{catalog.messages.retry}</Button></div>}
      <div className="fms-content" data-reveal={ready && !picker || undefined} style={{visibility:ready?'visible':'hidden'}} inert={!ready} aria-hidden={!ready}>
        {catalog&&<StudioLocalizationProvider value={{...catalog,pending:localePending,requestLocale}}><StudioApp key={JSON.stringify([options.workspaceId,options.identityScope])} {...options} portalContainer={container} onInitialReady={reveal}/></StudioLocalizationProvider>}
      </div>
    </>, container)}
  </div>;
}
