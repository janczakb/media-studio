import {createRoot} from 'react-dom/client';
import {DocumentClient, DocxView, XlsxView, PptxView,ViewerContext,printDocument,type FileOps} from '@p10node/office-engine';
import type {OfficePreviewMount,OfficePreviewHandle,OfficeFindState,OfficeViewportControl} from './office-preview-contract';

/** Lazy reader root. The host owns the worker even while open() is pending. */
export function mountOfficePreview(host: HTMLElement, options: OfficePreviewMount): OfficePreviewHandle {
  const root = createRoot(host);
  let find:OfficeFindState = options.search;
  let viewport:OfficeViewportControl=options.viewport;
  let render: (()=>void) | undefined;
  let client: DocumentClient | undefined;
  let worker: Worker | undefined;
  let disposed = false;
  let printCleanup:(()=>void)|undefined;
  let ready = false;
  const fileOps:{current:FileOps|null}={current:null};
  const selectAll:{current:(()=>void)|null}={current:null};
  let parsed = false;
  let observer: MutationObserver | undefined;
  const subscriptions: (() => void)[] = [];
  const failed = () => {if (!disposed) options.onError();};
  const rendered = () => {
    if (ready || disposed || !parsed) return;
    // A parsing-complete event can precede layout. Wait for actual page/cell/slide DOM.
    if (!host.querySelector('.de-page,.de-slide,.de-cell,.de-grid-view')) return;
    ready = true;
    observer?.disconnect();
    options.onReady();
  };
  void DocumentClient.open(options.url, {
    name: options.name, credentials: 'same-origin', residentCells: options.residentCells, pageRows: options.pageRows,
    worker: () => {
      worker = new Worker(new URL('./office-engine.worker.js', import.meta.url), {type: 'module'});
      worker.addEventListener('error', failed);
      return worker;
    },
    onProgress: progress => {if (!disposed) options.onProgress(progress);},
  }).then(value => {
    if (disposed) {value.destroy(); return;}
    client = value;
    parsed = value.kind === 'pptx';
    subscriptions.push(value.on('docx:done', result => {if (result.error) failed(); else {parsed = true;rendered();}}));
    subscriptions.push(value.on('xlsx:progress', result => {if (result.error) failed(); else if(result.done) {parsed = true;rendered();}}));
    observer = new MutationObserver(rendered);
    observer.observe(host, {childList: true, subtree: true});
    render = () => {
    const externalFind = {...find,onResult:options.onSearchResult};
    const externalViewport={...viewport,onChange:options.onViewport};
    const view = value.kind === 'docx' ? <DocxView zoomControls={options.floatingZoom} externalViewport={externalViewport} externalFind={externalFind} outlineEnabled={options.outline} client={value} initialZoom="fit" onStatus={rendered}/>
      : value.kind === 'xlsx' ? <XlsxView externalFind={externalFind} client={value} onStatus={rendered}/>
      : <PptxView zoomControls={options.floatingZoom} externalViewport={externalViewport} client={value} initialZoom="fit" thumbnails={false} onStatus={rendered}/>;
    root.render(<ViewerContext.Provider value={{bar:null,editable:false,setDirty:()=>{},save:async()=>{},canDownload:true,client:value,fileOps,selectAll}}><div className={'de-root' + (options.theme === 'dark' ? ' de-dark' : '')}><div className="de-body">{view}</div></div></ViewerContext.Provider>);
    };render();
  }, failed).catch(failed);
  return {async print(){
    if(disposed||!ready||!fileOps.current)throw new Error('Document is not ready');
    const printable=await fileOps.current.printable();
    if(!disposed){printCleanup?.();const cleanup=await printDocument(printable,options.name);if(disposed)cleanup();else printCleanup=cleanup;}
  },viewport(state){viewport=state;if(!disposed)render?.();},search(state){find=state;if(!disposed)render?.();},destroy(){
    if(disposed)return;
    disposed = true;printCleanup?.();
    observer?.disconnect();
    subscriptions.forEach(off => off());
    root.unmount();
    client?.destroy();
    worker?.removeEventListener('error', failed);
    // Settle open() before terminating a worker that has not returned its client yet.
    if (!client && worker?.onerror) worker.onerror.call(worker, new ErrorEvent('error', {message: 'Preview closed'}));
    worker?.terminate();
    host.replaceChildren();
  }};
}
