import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let browser,server,origin;
before(async()=>{
 const assets=new Map(await Promise.all(['studio.js','studio.css'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
 for(const [name,value]of await localeAssets({raw:true}))assets.set('/'+name,value);
 server=createServer((req,res)=>{const path=new URL(req.url,'http://localhost').pathname;res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':'text/html');res.end(assets.get(path)??'<!doctype html><div id="studio" style="height:100vh"></div>');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
async function open(width=1280,inspect=false,deep=false){
 const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(origin);
 await page.evaluate(async(deep)=>{
 const {mountMediaStudio}=await import('/studio.js');
 const base={mediaType:null,mimeType:null,size:null,versionId:null,updatedAt:'2026-11-10T00:00:00Z',revision:1,trashedAt:null};
 const folder=(id,name)=>({...base,id,name,kind:'folder',hasChildFolders:id==='archive'}),file={...base,id:'asset',name:'Report.txt',kind:'file'};
 window.moveRequests=[];window.moveWrites=[];window.moveAborted=0;window.moveFail=false;
 window.studio=mountMediaStudio(document.querySelector('#studio'),{theme:'light',transport:{
 async workspaces(){return {items:[{id:'w',name:'Workspace',role:'owner'}],canCreate:false};},
 async workspace(){return {id:'w',name:'Workspace',role:'owner'};},
 async status(){return {items:[]};},
 async details(){return {...file,parentId:null,metadata:{},createdAt:base.updatedAt};},
 async browse(_w,query,cursor,signal){
  if(query.view!=='folders')return {items:[file],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};
  window.moveRequests.push({parent:query.parent,cursor,search:query.search});
  await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,25);signal.addEventListener('abort',()=>{clearTimeout(timer);window.moveAborted++;reject(new DOMException('Aborted','AbortError'));},{once:true});});
  if(window.moveFail){window.moveFail=false;throw new Error('Temporary error');}
  let entries=query.parent===null?[folder('archive','Archive'),folder('other','Other'),folder('asset','Excluded source')]:query.parent==='archive'?Array.from({length:1200},(_,i)=>folder('child-'+i,'Folder '+String(i).padStart(4,'0'))):[];
  if(deep){const level=query.parent===null?0:query.parent==='archive'?1:Number(query.parent.slice(6))+1;entries=[{...folder(level===0?'archive':'level-'+level,'Level '+level+' '+('Very long folder name '.repeat(4))),hasChildFolders:level<8}];}
  if(query.search)entries=entries.filter(e=>e.name.toLowerCase().startsWith(query.search.toLowerCase()));
  const start=Number(cursor??0),limit=query.limit??60,items=entries.slice(start,start+limit),next=start+limit<entries.length?String(start+limit):null;
  return {items,nextCursor:next,previousCursor:start?String(Math.max(0,start-limit)):null,hasMore:next!==null,canWrite:true};
 },async update(w,id,values){window.moveWrites.push({w,id,values});return {...file,revision:2};},
 async createFolder(w,parent,name){window.moveWrites.push({w,parent,name});return folder('created',name);},
 }});
 },deep);
 await page.getByRole('button',{name:'More actions: Report.txt',exact:true}).click();if(inspect){await page.getByRole('menuitem',{name:'File information',exact:true}).click();await page.getByRole('button',{name:'Move',exact:true}).click();}else await page.getByRole('menuitem',{name:'Move',exact:true}).click();
 const dialog=page.getByRole('dialog',{name:'Move to…',exact:true});await expect(dialog.getByRole('checkbox',deep?{name:/^Level 0/}:{name:'Archive',exact:true})).toBeVisible();return {page,dialog,errors};
}
test('move uses independent expand/select controls and persists the selected destination',async()=>{
 const {page,dialog,errors}=await open();try{
 await expect(dialog.getByRole('button',{name:'Move',exact:true})).toBeDisabled();
 await expect(dialog.getByRole('checkbox',{name:'Excluded source'})).toHaveCount(0);
 await expect(dialog.locator('button[aria-expanded][aria-label=Other]')).toHaveCount(0);
 assert.equal(await page.evaluate(()=>window.moveRequests.length),1);
 await dialog.locator('button[aria-expanded][aria-label=Archive]').click();
 await expect(dialog.getByRole('checkbox',{name:'Folder 0000'})).toBeVisible();
 await expect(dialog.getByRole('button',{name:'Move',exact:true})).toBeDisabled();
 await dialog.getByRole('checkbox',{name:'Folder 0000'}).click();await dialog.getByRole('button',{name:'Move',exact:true}).click();
 await expect(dialog).toHaveCount(0);const writes=await page.evaluate(()=>window.moveWrites);assert.match(writes[0].values.requestId,/^[a-f0-9-]{36}$/);delete writes[0].values.requestId;assert.deepEqual(writes,[{w:'w',id:'asset',values:{revision:1,parent:'child-0'}}]);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('1200 children load lazily in cursor pages; DOM stays bounded and evicted pages reload',async()=>{
 const {page,dialog,errors}=await open();try{
 await dialog.locator('button[aria-expanded][aria-label=Archive]').click();await expect(dialog.getByRole('checkbox',{name:'Folder 0000'})).toBeVisible();
 assert.equal(await page.evaluate(()=>window.moveRequests.filter(r=>r.parent==='archive').length),1);
 for(let i=1;i<=10;i++){
  await dialog.locator('.fms-move-tree').evaluate(e=>e.scrollTop=e.scrollHeight-e.clientHeight);
  await expect.poll(()=>page.evaluate(()=>window.moveRequests.filter(r=>r.parent==='archive').length)).toBe(i+1);
  await expect(dialog.getByRole('checkbox',{name:`Folder ${String(i*50).padStart(4,'0')}`,exact:true})).toBeVisible();
 }
 assert.ok(await dialog.locator('.fms-move-row').count()<30);
 await dialog.locator('.fms-move-tree').evaluate(e=>e.scrollTop=0);
 await expect(dialog.getByRole('checkbox',{name:'Folder 0000',exact:true})).toBeVisible();
 assert.equal(await page.evaluate(()=>window.moveRequests.filter(r=>r.parent==='archive'&&(r.cursor===null||r.cursor==='0')).length),2);
 await dialog.locator('button[aria-expanded][aria-label=Archive]').click();await expect(dialog.getByRole('checkbox',{name:'Folder 0000'})).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
test('mobile layout, debounced search, create in selected folder and close cancellation',async()=>{
 const {page,dialog,errors}=await open(390);try{
 const box=await dialog.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=390);
 await dialog.getByRole('checkbox',{name:'Archive',exact:true}).click();await dialog.getByRole('button',{name:'Create sub-space',exact:true}).click();const create=page.getByRole('dialog',{name:'Create new folder',exact:true});await expect(create).toBeVisible();await expect(create.locator('.fms-create-destination-path')).toHaveText(/Spaces\s*\/\s*Archive/);await create.getByRole('textbox',{name:'Name',exact:true}).fill('New child');await create.getByRole('button',{name:/^Save/}).click();await expect(create).toHaveCount(0);
 await expect.poll(()=>page.evaluate(()=>window.moveWrites.length)).toBe(1);assert.deepEqual(await page.evaluate(()=>window.moveWrites[0]),{w:'w',parent:'archive',name:'New child'});
 await dialog.getByRole('searchbox').fill('Other');await expect(dialog.getByRole('checkbox',{name:'Other',exact:true})).toBeVisible();await expect(dialog.getByRole('checkbox',{name:'Archive',exact:true})).toHaveCount(0);
 await dialog.getByRole('button',{name:'Close',exact:true}).click();await expect(dialog).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('move opened from file information remains interactive and changes the create label',async()=>{
 const {page,dialog,errors}=await open(1280,true);try{
 await expect(dialog.getByRole('button',{name:'Create space',exact:true})).toBeVisible();
 await dialog.getByRole('checkbox',{name:'Archive',exact:true}).click();
 await expect(dialog).toBeVisible();await expect(dialog.getByRole('button',{name:'Create sub-space',exact:true})).toBeVisible();
 await dialog.locator('button[aria-expanded][aria-label=Archive]').click();await expect(dialog.getByRole('checkbox',{name:'Folder 0000'})).toBeVisible();
 const geometry=await dialog.evaluate(e=>{const h=e.querySelector('.fms-move-header'),c=e.querySelector('.fms-move-checkbox');return {width:e.getBoundingClientRect().width,direction:getComputedStyle(h).flexDirection,border:getComputedStyle(c).borderTopWidth};});
 assert.equal(geometry.width,588);assert.equal(geometry.direction,'row');assert.equal(geometry.border,'2px');
 await dialog.getByRole('button',{name:'Close',exact:true}).click();await expect(dialog).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('nested create cancels with Escape, keeps tree selection and saves with modifier+Enter',async()=>{
 const {page,dialog,errors}=await open();try{
 await dialog.getByRole('button',{name:'Create space',exact:true}).click();
 let create=page.getByRole('dialog',{name:'Create new folder',exact:true});
 await expect(create.locator('.fms-create-destination-path')).toHaveText('Spaces');
 await expect(create.getByRole('button',{name:/^Save/})).toBeDisabled();
 await expect(create.getByRole('textbox',{name:'Name',exact:true})).toBeFocused();
 await create.getByRole('textbox',{name:'Name',exact:true}).press('Escape');
 await expect(create).toHaveCount(0);await expect(dialog).toBeVisible();
 await dialog.getByRole('checkbox',{name:'Archive',exact:true}).click();
 await dialog.getByRole('button',{name:'Create sub-space',exact:true}).click();
 create=page.getByRole('dialog',{name:'Create new folder',exact:true});
 await create.getByRole('button',{name:/^Cancel/}).click();
 await expect(create).toHaveCount(0);await expect(dialog.getByRole('checkbox',{name:'Archive',exact:true})).toBeChecked();
 await dialog.getByRole('button',{name:'Create sub-space',exact:true}).click();
 create=page.getByRole('dialog',{name:'Create new folder',exact:true});
 await create.getByRole('textbox',{name:'Name',exact:true}).fill('Shortcut child');
 await create.getByRole('textbox',{name:'Name',exact:true}).press('Control+Enter');
 await expect(create).toHaveCount(0);await expect(dialog).toBeVisible();
 assert.deepEqual(await page.evaluate(()=>window.moveWrites),[{w:'w',parent:'archive',name:'Shortcut child'}]);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1280])test(`deep destination path truncates each segment without overflow at ${width}px`,async()=>{
 const {page,dialog,errors}=await open(width,false,true);try{
 for(let depth=0;depth<5;depth++){
  await dialog.locator(`button[aria-expanded][aria-label^="Level ${depth} "]`).click();
  await expect(dialog.getByRole('checkbox',{name:new RegExp('^Level '+(depth+1)+' ')})).toBeVisible();
 }
 await dialog.getByRole('checkbox',{name:/^Level 5 /}).click();
 await dialog.getByRole('button',{name:'Create sub-space',exact:true}).click();
 const create=page.getByRole('dialog',{name:'Create new folder',exact:true});
 const badge=create.locator('.fms-create-destination-path');
 await expect(badge.locator('.fms-create-path-name')).toHaveCount(7);
 await create.getByRole('textbox',{name:'Name',exact:true}).click();
 const geometry=await badge.evaluate(e=>({x:e.getBoundingClientRect().x,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width,names:[...e.querySelectorAll('.fms-create-path-name')].map(n=>({client:n.clientWidth,scroll:n.scrollWidth,ellipsis:getComputedStyle(n).textOverflow,title:n.title})),slashCount:e.querySelectorAll('.fms-create-path-separator').length,icons:e.querySelectorAll('svg').length}));
 assert.ok(geometry.x>=0&&geometry.right<=width,JSON.stringify(geometry));assert.ok(geometry.width<=500.5,JSON.stringify(geometry));
 assert.equal(geometry.slashCount,6);assert.equal(geometry.icons,1);
 assert.ok(geometry.names.every(n=>n.client>0&&n.ellipsis==='ellipsis'&&n.title));
 assert.ok(geometry.names.slice(1).every(n=>n.scroll>n.client),JSON.stringify(geometry));
 assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('row labels, icons and whitespace select once; disclosures only expand',async()=>{
 const {page,dialog,errors}=await open();try{
 const archive=dialog.getByRole('checkbox',{name:'Archive',exact:true});
 await dialog.getByRole('button',{name:'Archive',exact:true}).filter({hasNot:page.locator('svg')}).click();
 await expect(archive).toBeChecked();
 await expect(dialog.locator('button[aria-expanded][aria-label=Archive]')).toHaveAttribute('aria-expanded','false');
 await dialog.locator('button[aria-expanded][aria-label=Archive]').click();
 await expect(dialog.getByRole('checkbox',{name:'Folder 0000'})).toBeVisible();await expect(archive).toBeChecked();
 await archive.click();await expect(archive).not.toBeChecked();
 const row=archive.locator('..');await row.click({position:{x:6,y:30}});await expect(archive).toBeChecked();
 await row.locator(':scope > svg').click();await expect(archive).not.toBeChecked();
 await dialog.getByText('All files',{exact:true}).click();await expect(dialog.getByRole('checkbox',{name:'All files',exact:true})).toBeChecked();
 await expect(dialog.locator('.fms-move-root button[aria-expanded]')).toHaveAttribute('aria-expanded','true');
 assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
