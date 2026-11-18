import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let browser,server,origin;
before(async()=>{
 const assets=new Map(await Promise.all(['studio.js','studio.css','filament.svg','studio-mark.svg'].map(async name=>['/'+name,{body:await readFile(new URL('../../packages/react/dist/'+name,import.meta.url)),type:name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'image/svg+xml'}])));
 for(const [name,value]of await localeAssets({raw:false}))assets.set(name,value);
 assets.set('/pixel.png',{type:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==','base64')});
 server=createServer((req,res)=>{const a=assets.get(new URL(req.url,'http://localhost').pathname);res.writeHead(200,{'Content-Type':a?.type??'text/html'});res.end(a?.body??'<!doctype html><div id="studio" style="height:900px"></div>');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+server.address().port;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
async function open({theme='light',viewer=false,shortcuts}={}){
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(origin);
 await page.evaluate(async({theme,viewer,shortcuts})=>{
  const {mountMediaStudio}=await import('/studio.js');window.uploads=[];
  const workspace={id:'space',name:'Media library',role:viewer?'viewer':'owner'};
  const folder={id:'folder',name:'Destination',kind:'folder',mediaType:'folder',mimeType:null,size:0,revision:1,trashedAt:null,updatedAt:'2026-11-18T12:00:00Z'};
  const image={id:'image',name:'Photo.png',kind:'file',mediaType:'image',mimeType:'image/png',size:100,revision:1,versionId:'version',width:10,height:10,thumbnailUrl:'/filament.svg',trashedAt:null,updatedAt:folder.updatedAt};
  window.instance=mountMediaStudio(document.querySelector('#studio'),{locale:'en',theme,...(shortcuts===undefined?{}:{shortcuts}),transport:{
   async workspaces(){return {items:[workspace],canCreate:false};},
   async browse(_w,query){return {items:query.parent?[image]:[folder,image],nextCursor:null,previousCursor:null,hasMore:false,canWrite:!viewer};},
   async details(_w,id){return {...(id==='folder'?folder:image),parentId:null,metadata:{},createdAt:folder.updatedAt};},
   async settings(){return {revision:0,extensions:['png'],groups:{image:['png']},chunks:false,chunksAvailable:false,canManage:!viewer};},
   async upload(workspace,parent,file,key){window.uploads.push({workspace,parent,name:file.name,type:file.type,bytes:file.size,key});return {...image,id:'uploaded-'+window.uploads.length,name:file.name};},
   async bulk(){return {items:[]};},
   contentUrl(){return '/pixel.png';},
  }});
 },{theme,viewer,shortcuts});
 await expect(page.locator('.fms-title-row h1')).toHaveText('All files');return {page,errors};
}
async function settings(page){await page.locator('.fms-shell').click({position:{x:800,y:500}});await page.keyboard.press('Alt+Shift+S');await expect(page.locator('.fms-settings-dialog')).toBeVisible();await page.locator('.fms-settings-nav').getByRole('button',{name:'Keyboard shortcuts',exact:true}).click();await expect(page.locator('.fms-shortcuts-recorders')).toBeVisible();}
async function paste(page,{target='.fms-shell',name='image.png',bytes=3}={}){return page.locator(target).first().evaluate((el,{name,bytes})=>{const data=new DataTransfer();data.items.add(new File([new Uint8Array(bytes)],name,{type:'image/png'}));const event=new ClipboardEvent('paste',{clipboardData:data,bubbles:true,composed:true,cancelable:true});el.dispatchEvent(event);return event.defaultPrevented;},{name,bytes});}
for(const theme of ['light','dark'])test(theme+' recorder animates modifiers, transfers conflicts and resets bindings',async()=>{
 const {page,errors}=await open({theme});try{
  await settings(page);await expect(page.getByRole('tab',{name:'Mac',exact:true})).toBeVisible();await expect(page.getByRole('tab',{name:'Windows',exact:true})).toBeVisible();const recorder=page.locator('.fms-shortcuts-recorders .fms-arc-recorder').first();
  const mac=await page.evaluate(()=>/Mac|iPhone|iPad/.test(navigator.platform)),modifier=mac?'Meta':'Control',cap=mac?'⌘':'Ctrl';
  await page.getByRole('tab',{name:mac?'Windows':'Mac',exact:true}).click();await expect(recorder.locator('.fms-arc-kbd')).toHaveText([mac?'Ctrl':'⌘','K']);await page.getByRole('tab',{name:mac?'Mac':'Windows',exact:true}).click();
  await page.getByRole('tab',{name:'Windows',exact:true}).click();await expect(recorder.locator('.fms-arc-kbd')).toHaveText(['Ctrl','K']);await page.getByRole('tab',{name:mac?'Mac':'Windows',exact:true}).click();
  await recorder.click();await page.keyboard.down(modifier);await expect(recorder.locator('.fms-arc-kbd[data-pressed]')).toHaveText([cap]);await page.keyboard.press('j');await page.keyboard.up(modifier);await expect(recorder.locator('.fms-arc-kbd')).toHaveText([cap,'J']);
  const upload=page.locator('.fms-shortcuts-recorders .fms-arc-recorder').nth(1);await upload.click();await page.keyboard.press(modifier+'+j');await expect(page.locator('.fms-arc-message').filter({hasText:'Already used by'})).toBeVisible();await page.getByRole('button',{name:'Use anyway',exact:true}).click();await expect(upload.locator('.fms-arc-kbd')).toHaveText([cap,'J']);await expect(recorder.locator('.fms-arc-placeholder')).toBeVisible();
  const raw=await page.evaluate(()=>JSON.parse(localStorage.getItem('flex-media-studio.preferences.browser.shortcuts.v1')));assert.equal(raw.bindings.search,null);assert.equal(raw.bindings.upload,'Mod+J');
  await page.getByRole('button',{name:'Restore all defaults',exact:true}).click();await expect(recorder.locator('.fms-arc-kbd')).toHaveText([cap,'K']);
  await recorder.click();await page.keyboard.press('Escape');await expect(page.locator('.fms-settings-dialog')).toBeVisible();await expect(recorder).toHaveAttribute('aria-pressed','false');
  const input=page.locator('.fms-arc-search');await input.fill('paste');await expect(page.locator('.fms-arc-item')).toHaveCount(1);await input.press('Escape');await expect(input).toHaveValue('');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('clipboard upload uses the current folder once and preserves text fields and disabled/read-only policies',async()=>{
 const {page,errors}=await open();try{
  await page.getByRole('button',{name:'Folder: Destination',exact:true}).click();
  await expect(page.locator('.fms-title-row h1')).toHaveText('Destination');
  assert.equal(await paste(page),true);await expect.poll(()=>page.evaluate(()=>window.uploads.length)).toBe(1);
  const uploads=await page.evaluate(()=>window.uploads);assert.equal(uploads[0].parent,'folder');assert.equal(uploads[0].workspace,'space');assert.match(uploads[0].name,/^clipboard-/);
  await settings(page);assert.equal(await paste(page,{target:'.fms-arc-search'}),false);assert.equal(await page.evaluate(()=>window.uploads.length),1);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
 for(const config of [{viewer:true},{shortcuts:false},{shortcuts:{clipboard:false}}]){
  const {page}=await open(config);try{assert.equal(await paste(page),false);assert.equal(await page.evaluate(()=>window.uploads.length),0);}finally{await page.close();}
 }
});

test('explicit image copy writes a bounded PNG and leaves selected text copy native',async()=>{
 const {page,errors}=await open();try{
  await page.evaluate(()=>{window.copied=[];Object.defineProperty(navigator.clipboard,'write',{configurable:true,value:async items=>{for(const item of items){const blob=await item.getType('image/png');window.copied.push({type:blob.type,size:blob.size});}}});});
  await page.locator('[data-preview-entry="image"]').hover();await page.locator('[data-preview-entry="image"] .fms-card-checkbox').click();await expect(page.locator('[data-preview-entry="image"]').getByRole('checkbox')).toBeChecked();
  const primary=await page.evaluate(()=>/Mac|iPhone|iPad/.test(navigator.platform)?'Meta':'Control');
  await page.keyboard.press(primary+'+c');await expect.poll(()=>page.evaluate(()=>window.copied.length)).toBe(1);
  const copied=await page.evaluate(()=>window.copied[0]);assert.equal(copied.type,'image/png');assert.ok(copied.size>0&&copied.size<10000);
  await page.evaluate(()=>{const root=document.querySelector('#studio').firstElementChild.shadowRoot;const range=document.createRange();range.selectNodeContents(root.querySelector('.fms-title-row h1'));getSelection().removeAllRanges();getSelection().addRange(range);});
  await page.keyboard.press(primary+'+c');assert.equal(await page.evaluate(()=>window.copied.length),1);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
