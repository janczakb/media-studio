import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';

let server,browser,origin,documentBytes;
const calls=[];
const extraDocuments=new Map();
const formats=['docx','xlsx','pptx','pages','key','numbers','doc','xls','ppt','odt','ods','odp','rtf','csv','md'];
before(async()=>{
  for(const ext of ['xlsx','pptx']) extraDocuments.set('/file.'+ext,await readFile(new URL('../fixtures/documents/sample.'+ext,import.meta.url)));
  for(const ext of formats)extraDocuments.set('/samples/sample.'+ext,await readFile(new URL('../fixtures/documents/office-engine/sample.'+ext,import.meta.url)));
  extraDocuments.set('/many.csv',Buffer.from('Name,Value\n'+Array.from({length:20000},(_,index)=>`Record-${index},${index}`).join('\n')));
  extraDocuments.set('/note.txt',Buffer.from('Zażółć gęślą jaźń. Literal <b>tag</b> and **plain text**.\nSecond line.'));
  extraDocuments.set('/file.pdf',await readFile(new URL('../fixtures/documents/viewer-sample.pdf',import.meta.url)));
  documentBytes=await readFile(new URL('../fixtures/documents/preview-pages.docx',import.meta.url));
  const assets=new Map(await Promise.all(['print.css','pdf-preview.js','pdf-preview.css','pdfium-worker.js','pdfium.wasm','studio.js','studio.css','studio-mark.svg','filament.svg','office-preview.js','office-preview.css','office-engine.worker.js'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
  for(const [name,body] of await localeAssets({raw:true}))assets.set('/'+name,body);
  server=createServer((req,res)=>{
    const url=new URL(req.url,'http://localhost');calls.push(url.pathname);
    if(url.pathname==='/file.docx'||extraDocuments.has(url.pathname)){
      const bytes=extraDocuments.get(url.pathname)??documentBytes;
      const range=/bytes=(\d+)-(\d*)/.exec(req.headers.range??'');
      const start=Number(range?.[1]??0),end=Math.min(bytes.length-1,Number(range?.[2]||bytes.length-1));
      res.writeHead(range?206:200,{'Content-Type':'application/vnd.openxmlformats-officedocument.wordprocessingml.document','Content-Length':end-start+1,'Accept-Ranges':'bytes',...(range?{'Content-Range':`bytes ${start}-${end}/${bytes.length}`}:{})});res.end(bytes.subarray(start,end+1));return;
    }
    if(url.pathname==='/broken.docx'){res.writeHead(200);res.end('not an Office archive');return;}
    const asset=assets.get(url.pathname);
    res.writeHead(200,{'Content-Type':asset?(url.pathname.endsWith('.wasm')?'application/wasm':url.pathname.endsWith('.css')?'text/css':url.pathname.endsWith('.svg')?'image/svg+xml':'text/javascript'):'text/html'});
    res.end(asset??'<!doctype html><html><body style="margin:0"><div id="studio" style="height:100vh"></div></body></html>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
  browser=await chromium.launch();
  browser.on("page",page=>page.setDefaultTimeout(8000));
});
after(async()=>{await browser?.close();await new Promise(resolve=>server.close(resolve));});
async function open(page,{source='/file.docx',size=documentBytes.length,disabled=false,settings={},extension='docx',menu=true,pdfSettings=undefined,fileName=undefined,audioWaveform=undefined,openPreview=true}={}){
  await page.goto(origin);
  await page.evaluate(async({source,size,disabled,settings,extension,menu,pdfSettings,fileName,audioWaveform})=>{
    const RealWorker=window.Worker;window.liveOfficeWorkers=0;
    window.Worker=class extends RealWorker{constructor(...args){super(...args);this.alive=true;window.liveOfficeWorkers++;}terminate(){if(this.alive){this.alive=false;window.liveOfficeWorkers--;}super.terminate();}};
    const {mountMediaStudio}=await import('/studio.js');
    const entry={id:'doc1',name:fileName??'Office Preview.'+extension,kind:'file',mediaType:extension==='mp3'?'audio':'document',audioWaveform,mimeType:extension==='mp3'?'audio/mpeg':extension==='docx'?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/octet-stream',size,versionId:'v1',revision:1,updatedAt:'2026-11-10T00:00:00Z',trashedAt:null};
    window.instance=mountMediaStudio(document.querySelector('#studio'),{pdfPreview:pdfSettings,previewMenu:menu?entry=>[{id:'custom',label:'Custom '+entry.name,onPress:()=>{window.customPreviewAction=entry.id;}}]:false,documentPreview:disabled?false:{timeoutMs:5000,...settings},transport:{
      async workspaces(){return {items:[{id:'w',name:'Preview tests',role:'owner'}],canCreate:false};},
      async browse(){return {items:[entry],nextCursor:null,previousCursor:null,hasMore:false,canWrite:false};},
      async neighbors(){return {items:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:false};},
      async details(){return {...entry,metadata:{},parentId:null};},
      async createFolder(){},async upload(){},contentUrl(){return source;},
    }});
  },{source,size,disabled,settings,extension,menu,pdfSettings,fileName,audioWaveform});
  await expect(page.getByRole('button',{name:'Preview: '+(fileName??'Office Preview.'+extension),exact:true})).toBeVisible();
  if(!openPreview)return;
  await page.getByRole('button',{name:'Preview: '+(fileName??'Office Preview.'+extension),exact:true}).click();
  await expect(page.locator('.fms-preview-dialog')).toBeVisible();
}
test('DOCX loads only after preview intent, renders selectable text and releases its worker on close',async()=>{
  const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    let release;const blocked=new Promise(resolve=>{release=resolve;});
    await page.route('**/file.docx',async route=>{await blocked;await route.continue();});
    await open(page);
    const progress=page.locator('.fms-document-progress');
    await expect(progress).toBeVisible();await expect(progress).toHaveCSS('height','4px');
    await expect(page.locator('.fms-document-page-control')).toHaveCount(0);
    await expect(page.locator('.fms-preview-title h2')).toBeVisible();
    release();
    await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await expect(page.locator('.fms-office-host .de-page').first()).toBeVisible();
    await expect(page.locator('.fms-document-page-control')).toBeVisible();
    assert.ok((await page.locator('.fms-office-host .de-doc').first().innerText()).trim().length>10);
    assert.equal(await page.locator('.fms-office-host [contenteditable=true]').count(),0);
    const scroll=page.locator('.fms-office-host .de-scroll');
    await expect.poll(()=>scroll.evaluate(element=>element.scrollHeight>element.clientHeight)).toBe(true);
    await scroll.evaluate(element=>element.scrollTop=element.scrollHeight);
    await expect(page.locator('.fms-office-host').getByText(/Section 100:/)).toBeVisible();
    await scroll.evaluate(element=>element.scrollTop=0);
    await page.screenshot({path:'/tmp/flex-office-docx.png'});
    await expect(progress).toHaveCSS('opacity','0');
    assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),1);
    await page.locator('.fms-preview-back').click();
    await expect.poll(()=>page.evaluate(()=>window.liveOfficeWorkers)).toBe(0);
    await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
test('closing while a document is downloading immediately terminates the pending worker',async()=>{
  const page=await browser.newPage();
  try{
    let release;const blocked=new Promise(resolve=>{release=resolve;});
    await page.route('**/file.docx',async route=>{await blocked;await route.abort();});
    await open(page);await expect.poll(()=>page.evaluate(()=>window.liveOfficeWorkers)).toBe(1);
    await page.locator('.fms-preview-back').click();await expect.poll(()=>page.evaluate(()=>window.liveOfficeWorkers)).toBe(0);release();
  }finally{await page.close();}
});
test('corrupt files show retry and oversized files never start a reader or download',async()=>{
  for(const invalid of [false,true]){
    const page=await browser.newPage();
    try{
      const before=calls.filter(x=>x==='/file.docx').length;
      await open(page,{source:invalid?'/file.docx':'/broken.docx',size:invalid?60*1024*1024:100});
      await expect(page.locator('.fms-office-preview [role=alert]')).toBeVisible();
      if(invalid){assert.equal(calls.filter(x=>x==='/file.docx').length,before);assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),0);}
      else {
        await expect(page.getByRole('button',{name:'Try again',exact:true})).toBeVisible();
        await page.route('**/broken.docx',route=>route.fulfill({body:documentBytes,contentType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'}));
        await page.getByRole('button',{name:'Try again',exact:true}).click();
        await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
        await expect(page.locator('.fms-office-host .de-page')).toBeVisible();
      }
    }finally{await page.close();}
  }
});
test('disabled Office previews keep the lazy reader and its worker out of the page',async()=>{
  const page=await browser.newPage();
  try{
    const before=calls.filter(x=>x.startsWith('/office-')).length;
    await open(page,{disabled:true});await expect(page.locator('.fms-office-preview')).toHaveCount(0);
    assert.equal(calls.filter(x=>x.startsWith('/office-')).length,before);
  }finally{await page.close();}
});

test('XLSX and PPTX render their real contents in the same bounded read-only preview',async()=>{
  for(const extension of ['xlsx','pptx']){
    const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    try{
      await open(page,{source:'/file.'+extension,extension});
      await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
      await expect(page.locator(extension==='xlsx'?'.fms-office-host .de-cell':'.fms-office-host .de-slide').getByText(extension==='xlsx'?'Studio worksheet preview':'Studio presentation preview',{exact:true})).toBeVisible();
      assert.equal(await page.locator('.fms-office-host [contenteditable=true]').count(),0);
      await page.screenshot({path:'/tmp/flex-office-'+extension+'.png'});
      assert.deepEqual(errors,[]);
    }finally{await page.close();}
  }
});

test('a stalled download times out, releases resources and exposes retry',async()=>{
  const page=await browser.newPage();let release;const blocked=new Promise(resolve=>{release=resolve;});
  try{
    await page.route('**/file.docx',async route=>{await blocked;await route.abort();});
    await open(page);
    await expect(page.locator('.fms-office-preview [role=alert]')).toBeVisible({timeout:8000});
    assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),0);
    await expect(page.getByRole('button',{name:'Try again',exact:true})).toBeVisible();
  }finally{release();await page.close();}
});

test('audio grid renders a real waveform without requesting the original or a thumbnail',async()=>{
  const page=await browser.newPage();
  try{
    const start=calls.length;
    await open(page,{source:'/original.mp3',extension:'mp3',audioWaveform:'0f'.repeat(16),openPreview:false});
    const waveform=page.locator('.fms-audio-waveform path');
    await expect(waveform).toBeVisible();
    assert.equal((await waveform.getAttribute('d')).match(/M/g).length,32);
    assert.ok(!calls.slice(start).includes('/original.mp3'));
    await expect(page.locator('.fms-card-art.is-audio img')).toHaveCount(0);
  }finally{await page.close();}
});

test('TXT opens through the p10node reader and preserves literal markup',async()=>{
  const page=await browser.newPage();
  try{
    await open(page,{source:'/note.txt',extension:'txt',size:extraDocuments.get('/note.txt').length});
    await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false',{timeout:15000});
    await expect(page.locator('.fms-office-host .de-page').first()).toContainText('Zażółć gęślą jaźń. Literal <b>tag</b> and **plain text**.');
  }finally{await page.close();}
});

for(const extension of formats)test('real upstream '+extension+' sample opens read-only with the loading bar completing',async()=>{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  try {
    const start=performance.now();
    await open(page,{source:'/samples/sample.'+extension,extension,size:extraDocuments.get('/samples/sample.'+extension).length});
    await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false',{timeout:15000});
    const surface=page.locator('.fms-office-host .de-page,.fms-office-host .de-slide,.fms-office-host .de-cell');
    await expect(surface.first()).toBeVisible();
    assert.ok((await page.locator('.fms-office-host .de-root').innerText()).trim().length>15);
    assert.equal(await page.locator('.fms-office-host [contenteditable=true]').count(),0);
    if(extension==='pages'||extension==='md')await page.screenshot({path:'/tmp/flex-office-'+extension+'.png'});
    assert.deepEqual(errors,[]);
    console.log(extension+': '+Math.round(performance.now()-start)+'ms (local synthetic fixture, UI + engine + document)');
  }finally{await page.close();}
});

test('individual formats can be disabled without fetching the document or reader',async()=>{
  const page=await browser.newPage();
  try {
    const before=calls.filter(x=>x==='/file.docx').length;
    await open(page,{settings:{formats:['csv','md']}});
    await expect(page.locator('.fms-office-preview')).toHaveCount(0);
    assert.equal(calls.filter(x=>x==='/file.docx').length,before);
  }finally{await page.close();}
});

test('CSV rows beyond the resident budget are reachable without mounting the complete sheet',async()=>{
  const page=await browser.newPage();
  try {
    await open(page,{source:'/many.csv',extension:'csv',size:extraDocuments.get('/many.csv').length,settings:{residentCells:1000,pageRows:128}});
    await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false',{timeout:15000});
    const grid=page.locator('.fms-office-host .de-grid');
    await grid.evaluate(element=>element.scrollTop=19970*24);
    await expect.poll(async()=>await page.locator('.fms-office-host .de-cell').allTextContents()).toContain('Record-19999');
    assert.ok(await page.locator('.fms-office-host .de-cell').count()<2000);
    await page.locator('.fms-preview-back').click();
    await expect.poll(()=>page.evaluate(()=>window.liveOfficeWorkers)).toBe(0);
  }finally{await page.close();}
});

test('custom document search keeps one reader, navigates matches and hides native outline/find chrome',async()=>{
  const page=await browser.newPage();
  try{
    await open(page);await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await expect(page.locator('.de-outline-toggle')).toHaveCount(0);
    const before=await page.locator('.fms-office-host').boundingBox();
    const requestsBefore=calls.filter(path=>path==='/file.docx').length;
    await page.getByRole('button',{name:'Find in document',exact:true}).click();
    await expect(page.getByRole('search')).toBeVisible();
    await expect(page.locator('.fms-document-find-trigger')).toHaveAttribute('aria-pressed','true');
    await page.mouse.move(20,300);
    await expect(page.locator('.fms-document-find-trigger')).toHaveCSS('background-color','rgb(8, 8, 8)');
    await page.getByRole('searchbox',{name:'Find in document'}).fill('Section');
    await expect(page.locator('.fms-document-find-count')).toHaveText('1 of 100');
    await page.getByRole('button',{name:'Next match',exact:true}).hover();
    await expect(page.getByRole('button',{name:'Next match',exact:true})).toHaveCSS('background-color','rgba(0, 0, 0, 0.1)');
    await page.getByRole('button',{name:'Next match',exact:true}).click();
    await expect(page.locator('.fms-document-find-count')).toHaveText('2 of 100');
    await page.getByRole('button',{name:'Previous match',exact:true}).click();
    await expect(page.locator('.fms-document-find-count')).toHaveText('1 of 100');
    await page.getByRole('button',{name:'Previous match',exact:true}).click();
    await expect(page.locator('.fms-document-find-count')).toHaveText('100 of 100');
    await expect(page.locator('.de-doc').getByText(/Section 100:/)).toBeVisible();
    await expect(page.locator('.de-find')).toHaveCount(0);
    const after=await page.locator('.fms-office-host').boundingBox();assert.deepEqual(after,before);
    assert.equal(calls.filter(path=>path==='/file.docx').length,requestsBefore);
    assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),1);
    await page.screenshot({path:'/tmp/flex-office-custom-find.png'});
    await page.getByRole('search').getByRole('button',{name:'Clear search',exact:true}).click();
    await expect(page.getByRole('searchbox',{name:'Find in document'})).toHaveValue('');
    await expect(page.getByRole('search')).toBeVisible();
    await expect(page.locator('.fms-document-find-count')).toHaveText('0 of 0');
    assert.equal(await page.locator('.fms-document-find button').count(),2);
    await page.getByRole('button',{name:'Find in document',exact:true}).click();
    await expect(page.getByRole('search')).toHaveCount(0);await expect(page.locator('.fms-preview-dialog')).toBeVisible();
    await page.getByRole('button',{name:'Find in document',exact:true}).focus();
    await page.keyboard.press('Control+f');await expect(page.getByRole('search')).toBeVisible();
    await page.getByRole('searchbox',{name:'Find in document'}).fill('not-present-unique-query');
    await expect(page.locator('.fms-document-find-count')).toHaveText('0 of 0');
    await expect(page.getByRole('button',{name:'Next match',exact:true})).toBeDisabled();
  }finally{await page.close();}
});
test('custom worksheet search finds nonresident CSV cells through the worker',async()=>{
  const page=await browser.newPage();
  try{
    await open(page,{source:'/many.csv',extension:'csv',size:400000,settings:{residentCells:1000,pageRows:128}});
    await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await page.getByRole('button',{name:'Find in document',exact:true}).click();
    await page.getByRole('searchbox',{name:'Find in document'}).fill('Record-19999');
    await expect(page.locator('.fms-document-find-count')).toHaveText('1 of 1');
    await expect(page.locator('.de-cell').getByText('Record-19999',{exact:true})).toBeVisible();
    assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),1);
  }finally{await page.close();}
});
test('document search can be disabled while the optional outline remains available',async()=>{
  const page=await browser.newPage();
  try{
    await open(page,{settings:{search:false,outline:true}});await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await expect(page.getByRole('button',{name:'Find in document',exact:true})).toHaveCount(0);
    await expect(page.locator('.de-outline-toggle')).toBeVisible();
  }finally{await page.close();}
});
test('compact native search field fits mobile and clearing does not dismiss the panel',async()=>{
  const page=await browser.newPage({viewport:{width:390,height:844}});
  try{
    await open(page);await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await page.getByRole('button',{name:'Find in document',exact:true}).click();
    const panel=page.getByRole('search');
    await panel.getByRole('searchbox').fill('Section');
    await expect(panel.locator('output')).toHaveText('1 of 100');
    await expect(panel.locator('[data-slot="input-group-prefix"]')).toHaveCSS('padding-left','12px');
    await expect(panel.locator('.fms-document-find-navigation').first()).toHaveCSS('width','34px');
    await expect(panel.locator('.fms-document-find-navigation svg').first()).toHaveCSS('width','24px');
    const bounds=await panel.boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=390);
    assert.ok(await panel.evaluate(el=>el.scrollWidth<=el.clientWidth));
    await expect(panel.locator('[data-slot="search-field-clear-button"]')).toBeVisible();
    await panel.getByRole('button',{name:'Clear search',exact:true}).click();
    await expect(panel.getByRole('searchbox')).toHaveValue('');await expect(panel).toBeVisible();
    await page.getByRole('button',{name:'Find in document',exact:true}).click();await expect(panel).toHaveCount(0);
  }finally{await page.close();}
});
test('preview header reports real pages, navigates without reopening, and owns configurable zoom',async()=>{
  const page=await browser.newPage({viewport:{width:1280,height:800}});
  try{
    await open(page);await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    await expect(page.locator('.fms-preview-header')).toHaveCSS('height','56px');
    await expect(page.locator('.fms-inspector-header')).toHaveCSS('height','56px');
    await expect(page.locator('.fms-preview-tools .fms-preview-back')).toHaveCSS('width','32px');
    await expect(page.locator('.fms-preview-tools .fms-preview-back')).toHaveCSS('border-radius','8px');
    await expect(page.locator('.de-zoom')).toHaveCount(0);
    const input=page.getByRole('textbox',{name:'Page',exact:true});
    await expect(input).toHaveValue('1');
    const total=await page.locator('.fms-document-page-control>span').last().innerText();assert.ok(Number(total)>1);
    const workerCount=await page.evaluate(()=>window.liveOfficeWorkers);
    const requestCount=calls.filter(path=>path==='/file.docx').length;
    await input.fill(total);await input.press('Enter');await expect(input).toHaveValue(total);
    assert.equal(await input.evaluate(el=>el.getRootNode().activeElement===el),false);
    await input.fill('1');await input.press('Enter');await expect(input).toHaveValue('1');
    await page.locator('.fms-office-host .de-scroll').evaluate(el=>el.scrollTop=el.scrollHeight);
    await expect(input).toHaveValue(total);assert.equal(await input.evaluate(el=>el.getRootNode().activeElement===el),false);
    const zoom=page.getByRole('group',{name:'Document zoom',exact:true});
    const oldZoom=await zoom.locator('.fms-document-zoom-value').innerText();
    await zoom.getByRole('button',{name:'Zoom in',exact:true}).click();
    await expect(zoom.locator('.fms-document-zoom-value')).not.toHaveText(oldZoom);
    assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),workerCount);
    assert.equal(calls.filter(path=>path==='/file.docx').length,requestCount);
    const header=await page.locator('.fms-preview-header').boundingBox(),close=await page.locator('.fms-preview-back').boundingBox();
    assert.ok(Math.abs(header.x+header.width-close.x-close.width-12)<=1);
    await page.screenshot({path:'/tmp/flex-office-header.png'});
  }finally{await page.close();}
});
test('floating zoom and disabled zoom are explicit SDK choices',async()=>{
  for(const mode of ['floating',false]){
    const page=await browser.newPage();
    try{
      await open(page,{settings:{zoomControls:mode}});await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
      await expect(page.getByRole('group',{name:'Document zoom',exact:true})).toHaveCount(0);
      await expect(page.locator('.de-zoom')).toHaveCount(mode==='floating'?1:0);
    }finally{await page.close();}
  }
});

test('header menu resolves file actions and prints actual document pages in an isolated frame',async()=>{
  const page=await browser.newPage({viewport:{width:1280,height:800}});
  try{
    await page.addInitScript(()=>{window.printCalls=0;window.print=()=>{window.top.printCalls++;};document.execCommand=()=>false;});
    await open(page);await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
    const zoom=page.getByRole('group',{name:'Document zoom',exact:true});
    const header=await page.locator('.fms-preview-header').boundingBox(),box=await zoom.boundingBox();
    assert.ok(Math.abs(box.x+box.width/2-header.x-header.width/2)<1);
    await expect(zoom.getByRole('button',{name:'Zoom in',exact:true})).toHaveCSS('background-color','rgb(245, 245, 245)');
    await page.getByRole('button',{name:'More actions',exact:true}).click();
    await page.getByRole('menuitem',{name:'Custom Office Preview.docx',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.customPreviewAction),'doc1');
    await page.getByRole('button',{name:'More actions',exact:true}).click();
    await page.getByRole('menuitem',{name:'Print',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.printCalls)).toBe(1);
    const printFrame=page.frames().find(frame=>frame!==page.mainFrame());
    assert.ok(printFrame);assert.ok(await printFrame.locator('.de-print-page').count()>1);
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await expect.poll(()=>page.frames().length).toBe(1);
  }finally{await page.close();}
});
test('header dropdown can be disabled through the SDK',async()=>{
  const page=await browser.newPage();
  try{await open(page,{menu:false});await expect(page.getByRole('button',{name:'More actions',exact:true})).toHaveCount(0);}finally{await page.close();}
});

test('PDF uses the lazy native viewer, shares page/zoom controls and releases its worker',async()=>{
 const page=await browser.newPage({viewport:{width:1280,height:800}});
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 try{
  await open(page,{source:'/file.pdf',extension:'pdf'});
  await expect(page.locator('.fms-pdf-preview')).toHaveAttribute('aria-busy','false');
  await expect(page.locator('.fms-pdf-preview .fm-flow-page')).not.toHaveCount(0);
  const input=page.getByRole('textbox',{name:'Page',exact:true});
  await input.fill('4');await input.press('Enter');await expect(input).toHaveValue('4');
  const zoom=page.getByRole('group',{name:'Document zoom',exact:true}),before=await zoom.innerText();
  await zoom.getByRole('button',{name:'Zoom in',exact:true}).click();await expect(zoom).not.toHaveText(before);
  assert.equal(await page.evaluate(()=>window.liveOfficeWorkers),1);
  assert.ok(calls.includes('/pdfium.wasm'));
  assert.equal(await page.locator('.fms-pdf-preview .fm-flow-page').count()<=8,true);
  await page.getByRole('button',{name:'Close',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.liveOfficeWorkers)).toBe(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('disabled PDF previews do not load the engine, source or worker',async()=>{
 const page=await browser.newPage(),start=calls.length;
 try{await open(page,{source:'/file.pdf',extension:'pdf',pdfSettings:false});await expect(page.locator('.fms-pdf-preview')).toHaveCount(0);assert.equal(calls.slice(start).some(path=>['/pdfium.wasm','/pdfium-worker.js','/pdf-preview.js','/file.pdf'].includes(path)),false);}finally{await page.close();}
});

test('PDF external header search, progress and print work through the native SDK',async()=>{
 const page=await browser.newPage({viewport:{width:1280,height:800}});
 try{
  await page.addInitScript(()=>{window.pdfPrints=0;window.print=()=>{window.pdfPrints=document.querySelectorAll('.fm-print-page').length;queueMicrotask(()=>window.dispatchEvent(new Event('afterprint')));};});
  await open(page,{source:'/file.pdf',extension:'pdf'});
  await expect(page.locator('.fms-pdf-preview')).toHaveAttribute('aria-busy','false');
  await expect(page.locator('.fms-pdf-preview .fms-document-progress')).toHaveAttribute('data-complete','true');
  await page.getByRole('button',{name:'Find in document',exact:true}).click();
  await page.getByRole('searchbox',{name:'Find in document',exact:true}).fill('FLEX');
  await expect(page.locator('.fms-document-find-count')).toHaveText('1 of 8');
  await expect(page.locator('.fm-reader-search-hit')).not.toHaveCount(0);
  await page.getByRole('button',{name:'Next match',exact:true}).click();
  await expect(page.locator('.fms-document-find-count')).toHaveText('2 of 8');
  await page.getByRole('button',{name:'More actions',exact:true}).click();
  await page.getByRole('menuitem',{name:'Print',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.pdfPrints)).toBe(4);
  await expect(page.locator('.fm-print-document')).toHaveCount(0);
 }finally{await page.close();}
});

test('long preview filenames truncate without pushing page controls, zoom or header actions',async()=>{
 for(const width of [320,390,768,1024,1280])for(const extension of ['docx','pdf','bin']){
  const page=await browser.newPage({viewport:{width,height:800}});
  try{
   await open(page,{source:extension==='pdf'?'/file.pdf':'/file.docx',extension,fileName:'Very_Long_Name_Without_Breaks_'.repeat(25)+'.'+extension});
   if(extension!=='bin')await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
   const result=await page.locator('.fms-preview-header').evaluate(header=>{
    const title=header.querySelector('.fms-preview-title'),name=header.querySelector('.fms-preview-basename'),tools=header.querySelector('.fms-preview-tools'),h=header.getBoundingClientRect(),a=title.getBoundingClientRect(),b=tools.getBoundingClientRect(),zoom=header.querySelector('.fms-document-header-zoom')?.getBoundingClientRect();
    return {height:h.height,overflow:header.scrollWidth>header.clientWidth+1,truncated:name.scrollWidth>name.clientWidth,ellipsis:getComputedStyle(name).textOverflow,overlap:a.right>b.left+.5,toolsInside:b.right<=h.right&&b.left>=h.left,titleInside:a.right<=h.right,zoomOverlap:zoom&&zoom.top<h.bottom?a.right>zoom.left+.5:false,fullTitle:header.querySelector('h2').getAttribute('title')};
   });
   assert.equal(result.height,56,JSON.stringify({width,extension,result}));assert(!result.overflow&&!result.overlap&&result.toolsInside&&result.titleInside&&!result.zoomOverlap,JSON.stringify({width,extension,result}));
   assert(result.truncated);assert.equal(result.ellipsis,'ellipsis');assert(result.fullTitle.length>500);
  }finally{await page.close();}
 }
});

test('short filename and page count stay adjacent on the same baseline like Fabric',async()=>{
 for(const width of [320,390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:800}});
  try{
   await open(page,{fileName:'Short.docx'});await expect(page.locator('.fms-office-preview')).toHaveAttribute('aria-busy','false');
   const result=await page.locator('.fms-preview-name').evaluate(node=>{const title=node.querySelector('h2').getBoundingClientRect(),pager=node.querySelector('.fms-document-page-control').getBoundingClientRect();return {gap:pager.left-title.right,centerDelta:Math.abs(title.y+title.height/2-pager.y-pager.height/2),heading:node.querySelector('h2').scrollWidth>node.querySelector('h2').clientWidth}});
   assert(Math.abs(result.gap-8)<1,JSON.stringify({width,result}));assert(result.centerDelta<1,JSON.stringify({width,result}));
  }finally{await page.close();}
 }
});
