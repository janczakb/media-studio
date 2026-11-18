import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let browser,server,origin;
before(async()=>{
 const assets=new Map(await Promise.all(['studio.js','studio.css','filament.svg'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
 for(const [name,body]of await localeAssets({raw:true}))assets.set('/'+name,body);
 server=createServer((req,res)=>{const path=new URL(req.url,'http://localhost').pathname;res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/html');res.end(assets.get(path)??'<!doctype html><body style="margin:0"><div id="studio" style="height:100vh"></div>');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
async function open(width=1280,{delayFolders=0,wideRoot=false,targetAtPageEnd=false,restoredBranch=false}={}){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',error=>errors.push(error.message));
 await page.goto(origin+'/?fmsWorkspace=one&fmsFolder=deep');
 const boot=()=>page.evaluate(async({delayFolders,wideRoot,targetAtPageEnd,restoredBranch})=>{
  const {mountMediaStudio}=await import('/studio.js');
  const base={kind:'folder',mediaType:null,mimeType:null,size:null,versionId:null,revision:1,trashedAt:null,updatedAt:'2026-11-12T10:00:00Z'};
  const folder=(id,name,children=false)=>({...base,id,name,hasChildFolders:children});
  window.searchCalls=[];window.folderCalls=[];
  if(restoredBranch)localStorage.setItem('flex-media-studio.preferences.browser.folders.one',JSON.stringify([null,...Array.from({length:31},(_,i)=>'earlier-'+i)]));
  window.app=mountMediaStudio(document.querySelector('#studio'),{syncUrl:true,theme:'light',transport:{
   async workspaces(){return {items:[{id:'one',name:'Workspace',role:'owner'}],canCreate:false};},
   async workspace(){return {id:'one',name:'Workspace',role:'owner'};},
   async details(_,id){return {...folder(id,id==='deep'?'Deep folder':'Parent',true),parentId:id==='deep'?'parent':null,metadata:{},createdAt:base.updatedAt};},
   async browse(_,query,cursor){window.folderCalls.push({query,cursor});if(delayFolders&&query.view==='folders')await new Promise(resolve=>setTimeout(resolve,delayFolders));const children=Array.from({length:1000},(_,i)=>folder('child-'+i,'Child '+i));const all=query.view==='folders'?(query.parent===null?(restoredBranch?[folder('earlier-0','Earlier branch',true),folder('parent','Parent',true)]:targetAtPageEnd?[...Array.from({length:49},(_,i)=>folder('sibling-'+i,'A sibling '+String(i).padStart(5,'0'))),folder('parent','Parent',true)]:wideRoot?[...Array.from({length:5000},(_,i)=>folder('sibling-'+i,'A sibling '+String(i).padStart(5,'0'))),folder('parent','Parent',true)]:[folder('parent','Parent',true)]):query.parent==='parent'?(restoredBranch?[...Array.from({length:38},(_,i)=>folder('before-'+i,'Before '+i)),folder('deep','Deep folder',true),...Array.from({length:11},(_,i)=>folder('after-'+i,'Later '+i))]:[folder('deep','Deep folder',true)]):query.parent?.startsWith('earlier-')?(Number(query.parent.slice(8))<30?[folder('earlier-'+(Number(query.parent.slice(8))+1),'Earlier '+(Number(query.parent.slice(8))+1),true)]:[]):children):[];const start=Number(cursor??0),limit=query.limit??50;return {items:all.slice(start,start+limit),nextCursor:start+limit<all.length?String(start+limit):null,previousCursor:start?String(Math.max(0,start-limit)):null,hasMore:start+limit<all.length,canWrite:true};},
   async librarySearch(_,query,cursor,signal){window.searchCalls.push({query,cursor});if(query.text==='slow')await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,300);signal.addEventListener('abort',()=>{clearTimeout(timer);reject(new DOMException('Aborted','AbortError'));},{once:true});});const start=Number(cursor??0);const items=Array.from({length:query.limit??24},(_,i)=>({...folder('r'+(start+i),'Result '+(start+i)),kind:query.kind??'file',parentName:'Parent'}));return {items,nextCursor:String(start+items.length),hasMore:true};},
   async status(){return {items:[]};},async neighbors(){return {items:[],hasMore:false,nextCursor:null,previousCursor:null,canWrite:true};},contentUrl(){return '/content';},
  }});
 },{delayFolders,wideRoot,targetAtPageEnd,restoredBranch});
 await boot();
 await expect(page.getByRole('heading',{name:'Deep folder',exact:true})).toBeVisible();
 return {page,errors,boot};
}
test('navigation uses slash breadcrumbs in the topbar with an icon only on the last folder',async()=>{
 const {page,errors}=await open();try{
 const header=page.locator('.fms-header');await expect(header.locator('input')).toHaveCount(0);
 const trail=header.getByRole('list',{name:'All files',exact:true});await expect(trail).toBeVisible();
 await expect(trail.getByText('Parent',{exact:true})).toBeVisible();await expect(trail.getByText('Deep folder',{exact:true})).toBeVisible();
 const ancestor=trail.getByRole('link',{name:'Parent',exact:true});await ancestor.hover();
 await expect(ancestor).toHaveCSS('text-decoration-line','underline');
 const nativeHover=await ancestor.evaluate(el=>{const style=getComputedStyle(el),label=getComputedStyle(el.querySelector('.fms-breadcrumb-label'));return {color:style.color,decoration:style.textDecorationColor,labelDecoration:label.textDecorationColor,labelLine:label.textDecorationLine,overflow:style.overflow};});
 if(process.env.FMS_CAPTURE_SPOTLIGHT)await page.screenshot({path:'.internal/breadcrumb-hover.png'});
 assert.equal(nativeHover.labelLine,'underline');assert.equal(nativeHover.labelDecoration,nativeHover.decoration);assert.notEqual(nativeHover.color,nativeHover.decoration);assert.notEqual(nativeHover.overflow,'hidden');
 assert.equal(await trail.locator('img').count(),1);assert.equal(await trail.locator('[data-slot=breadcrumbs-separator]').first().textContent(),'/');
 await expect(page.locator('.fms-library-header .fms-breadcrumbs')).toHaveCount(0);
 await header.getByRole('button',{name:'Back',exact:true}).click();await expect(page.getByRole('heading',{name:'Parent',exact:true})).toBeVisible();assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('sidebar footer follows short content and is constrained below a long virtual tree',async()=>{
 const {page,errors}=await open();try{
 const root=page.locator('.fms-folder-root').first();const footer=page.locator('.fms-sidebar-footer');
 const before=await footer.boundingBox();assert.ok(before.y<400);
 await page.locator('.fms-folder-disclosure[aria-label="Deep folder"]').click();
 await expect(page.getByRole('tree',{name:'Folder',exact:true}).getByRole('button',{name:'Child 0',exact:true}).first()).toBeVisible();
 await expect.poll(async()=>(await footer.boundingBox()).y).toBeGreaterThan(600);
 const footerRect=await footer.boundingBox(),sidebar=await page.locator('.fms-sidebar').boundingBox();assert.ok(footerRect.y+footerRect.height<=sidebar.y+sidebar.height+1);
 await page.locator('.fms-folder-disclosure[aria-label="Folder: All files"]').click();
 const closed=await footer.boundingBox(),rootRect=await root.boundingBox();assert.ok(closed.y-rootRect.y-rootRect.height<20);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('cold deep-link hydration shows a placeholder until the visible tree pages are ready',async()=>{
 const {page,errors}=await open(1280,{delayFolders:350});try{
  const tree=page.locator('.fms-sidebar-folders');
  await expect(tree).toHaveAttribute('aria-busy','true');
  await expect(tree.locator('.fms-folder-tree-placeholder')).toBeVisible();
  await expect(tree.locator('.fms-folder-tree-content')).toBeHidden();
  await expect(tree).toHaveAttribute('aria-busy','false');
  await expect(tree.locator('.fms-sidebar-folder-name').filter({hasText:'Deep folder'})).toBeVisible();
  await expect(tree.locator('.fms-folder-tree-placeholder')).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('URL selection stays visible without enumerating five thousand preceding siblings',async()=>{
 const {page,errors}=await open(1280,{delayFolders:100,wideRoot:true});try{
  const tree=page.locator('.fms-sidebar-folders');await expect(tree).toHaveAttribute('aria-busy','false');
  const selected=tree.locator('[role=treeitem][aria-selected=true]');await expect(selected).toContainText('Deep folder');
  const row=await selected.boundingBox(),viewport=await tree.locator('[role=tree]').boundingBox();
  assert.ok(row.y>=viewport.y&&row.y+row.height<=viewport.y+viewport.height);
  const calls=await page.evaluate(()=>window.folderCalls.filter(call=>call.query.view==='folders'));
  assert.ok(calls.length<=3,JSON.stringify(calls));assert.ok(calls.every(call=>!call.cursor));
  assert.ok(await tree.locator('[role=treeitem]').count()<25);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('reload keeps the sidebar height and respects the natural end of the folder list',async()=>{
 const {page,errors,boot}=await open(1280,{delayFolders:180,targetAtPageEnd:true});try{
  const tree=page.locator('.fms-sidebar-folders'),footer=page.locator('.fms-sidebar-footer');
  await expect(tree).toHaveAttribute('aria-busy','false');
  const selected=tree.locator('[role=treeitem][aria-selected=true]');
  const offset=async()=>{const row=await selected.boundingBox(),viewport=await tree.locator('[role=tree]').boundingBox();return row.y-viewport.y;};
  assert.ok(await offset() <= await tree.locator('[role=tree]').evaluate(el=>el.clientHeight)-35);
  const before=(await footer.boundingBox()).y;
  await expect.poll(()=>page.evaluate(()=>Number(localStorage.getItem('flex-media-studio.preferences.browser.folders.one.height')))).toBeGreaterThan(160);
  await page.reload();await boot();
  await expect(tree).toHaveAttribute('aria-busy','true');
  assert.ok(Math.abs((await footer.boundingBox()).y-before)<2,'placeholder preserves the previous sidebar geometry');
  await expect(tree).toHaveAttribute('aria-busy','false');
  assert.ok(await offset() <= await tree.locator('[role=tree]').evaluate(el=>el.clientHeight)-35);
  assert.ok(Math.abs((await footer.boundingBox()).y-before)<2,'ready content preserves the footer position');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('upward manual scrolling is not pulled back to the selection when the viewport changes',async()=>{
 const {page,errors}=await open(1280,{delayFolders:100,targetAtPageEnd:true});try{
  const folders=page.locator('.fms-sidebar-folders');await expect(folders).toHaveAttribute('aria-busy','false');
  const tree=folders.locator('[role=tree]');
  await expect.poll(()=>tree.evaluate(el=>el.scrollTop)).toBeGreaterThan(1000);
  const initialPosition=await tree.evaluate(el=>el.scrollTop);
  await tree.hover();await page.mouse.wheel(0,-500);
  await expect.poll(()=>tree.evaluate(el=>el.scrollTop)).toBeLessThan(initialPosition-490);
  await tree.evaluate(el=>new Promise(resolve=>{let previous=el.scrollTop,stable=0;const frame=()=>{const current=el.scrollTop;stable=Math.abs(current-previous)<.1?stable+1:0;previous=current;if(stable>=3)resolve();else requestAnimationFrame(frame);};requestAnimationFrame(frame);}));
  const position=await tree.evaluate(el=>el.scrollTop);
  const previousHeight=await tree.evaluate(el=>el.clientHeight);
  await page.setViewportSize({width:1280,height:820});
  await expect.poll(()=>tree.evaluate(el=>el.clientHeight)).toBeLessThan(previousHeight);
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.ok(Math.abs(await tree.evaluate(el=>el.scrollTop)-position)<2,'resize must not restart automatic selection reveal');
  await expect(tree).toHaveCSS('overflow-anchor','none');assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('slow upward scrolling defers restored branch changes until idle and preserves the visible anchor',async()=>{
 const {page,errors}=await open(1280,{delayFolders:45,restoredBranch:true});try{
  const tree=page.locator('.fms-sidebar-folder-tree');await expect(page.locator('.fms-sidebar-folders')).toHaveAttribute('aria-busy','false');
  await tree.hover();await page.mouse.wheel(0,-12);
  const frames=await tree.evaluate(el=>new Promise(resolve=>{
   const samples=[];let previous=null,count=0;
   const tick=()=>{
    el.scrollTop=Math.max(0,el.scrollTop-4);
    const top=el.getBoundingClientRect().top;
    const current={total:el.scrollHeight,rows:[...el.querySelectorAll('[data-folder-key]')].filter(n=>n.getBoundingClientRect().bottom>top).map(n=>({key:n.dataset.folderKey,parent:n.dataset.folderParent,page:n.dataset.folderPage,text:n.textContent,y:n.getBoundingClientRect().top}))};
    if(previous&&current.total!==previous.total){const old=previous.rows[0];const same=old&&(current.rows.find(row=>row.key===old.key)??(!old.text&&current.rows.find(row=>row.parent===old.parent&&row.page===old.page)));samples.push({oldTotal:previous.total,total:current.total,shift:same?same.y-old.y:null});}
    previous=current;
    if(++count<300)requestAnimationFrame(tick);else resolve({samples,total:current.total,anchor:current.rows[0]});
   };requestAnimationFrame(tick);
  }));
  assert.equal(frames.samples.length,0,'active scrolling must keep the displayed projection stable');
  await expect.poll(()=>tree.evaluate(el=>el.scrollHeight)).not.toBe(frames.total);
  const anchorAfter=await tree.evaluate((el,saved)=>{const rows=[...el.querySelectorAll('[data-folder-key]')];const row=rows.find(n=>n.dataset.folderKey===saved.key)??(!saved.text&&rows.find(n=>n.dataset.folderParent===saved.parent&&n.dataset.folderPage===saved.page));return row?.getBoundingClientRect().top;},frames.anchor);
  if(anchorAfter!==undefined)assert.ok(Math.abs(anchorAfter-frames.anchor.y)<=4,'idle publication preserves the current anchor');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('the final folder stops at the footer without a scrollable empty tail',async()=>{
 const {page,errors}=await open(1280,{targetAtPageEnd:true});try{
  const folders=page.locator('.fms-sidebar-folders');await expect(folders).toHaveAttribute('aria-busy','false');
  const tree=folders.locator('[role=tree]');await tree.hover();await page.mouse.wheel(0,10000);
  await expect.poll(()=>tree.evaluate(el=>Math.abs(el.scrollHeight-el.clientHeight-el.scrollTop))).toBeLessThan(1);
  const geometry=await tree.evaluate(el=>{const last=[...el.querySelectorAll('[data-folder-key]')].at(-1);return {bottom:el.getBoundingClientRect().bottom,last:last.getBoundingClientRect().bottom};});
  assert.ok(Math.abs(geometry.bottom-geometry.last)<=5,JSON.stringify(geometry));assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
for(const width of [390,1280])test('dock opens a full-screen search overlay with preserved filters, virtual paging and dismiss at '+width,async()=>{
 const {page,errors}=await open(width);try{
 const dock=page.locator('.fms-preview-actionbar');await dock.getByRole('button',{name:'Search by name…',exact:true}).click();
 const dialog=page.getByRole('dialog',{name:'Search library',exact:true});await expect(dialog).toBeVisible();
 const backdrop=page.locator('.fms-spotlight-backdrop'),rect=await backdrop.boundingBox();assert.equal(Math.round(rect.width),width);assert.equal(Math.round(rect.height),900);
 const box=await dialog.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width,JSON.stringify(box));assert.ok(Math.abs(box.x+box.width/2-width/2)<1);assert.ok(box.y<120,'Spotlight stays near the top of the viewport');
 await dialog.evaluate(async el=>{await Promise.all(el.parentElement.getAnimations().map(animation=>animation.finished));});
 if(process.env.FMS_CAPTURE_SPOTLIGHT)await page.screenshot({path:'.internal/spotlight-'+width+'.png'});
 const input=dialog.getByRole('searchbox',{name:'Search library',exact:true});await expect(input).toBeFocused();await input.fill('Report');
 await expect(dialog.getByRole('option',{name:/Result 0/})).toBeVisible();
 await dialog.getByRole('button',{name:'Filters',exact:true}).click();await expect(dialog.locator('.fms-search-filter-disclosure')).toHaveAttribute('data-expanded','true');
 const list=dialog.getByRole('listbox',{name:'Search results',exact:true});await list.evaluate(el=>{el.scrollTop=el.scrollHeight;});
 await expect.poll(()=>page.evaluate(()=>window.searchCalls.some(call=>call.cursor==='24'))).toBe(true);
 assert.ok(await list.getByRole('option').count()<30);
 await input.fill('slow');await input.fill('Final');await expect.poll(()=>page.evaluate(()=>window.searchCalls.at(-1).query.text)).toBe('Final');
 await page.keyboard.press('Escape');await expect(dialog).toBeHidden();
 await dock.getByRole('button',{name:'Search by name…',exact:true}).click();await expect(input).toHaveValue('Final');await expect(dialog.locator('.fms-search-filter-disclosure')).toHaveAttribute('data-expanded','true');
 await dialog.getByRole('button',{name:'Close',exact:true}).click();await expect(dialog).toBeHidden();
 await page.keyboard.press('s');await expect(dialog).toBeVisible();await page.mouse.click(4,4);await expect(dialog).toBeHidden();assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
