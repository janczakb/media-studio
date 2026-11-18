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
  server=createServer((request,response)=>{const path=new URL(request.url,'http://localhost').pathname;response.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/html');response.end(assets.get(path)??'<!doctype html><body style="margin:0"><div id="studio" style="height:100vh"></div>');});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
async function open({deep=false,incremental=false,selectedRoot=false,restoreRoot=false,deepRestore=false}={}){
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(origin+(selectedRoot?'/?fmsWorkspace=one&fmsFolder='+(typeof selectedRoot==='string'?selectedRoot:'leaf'):deep?'/?fmsWorkspace=one&fmsFolder=deep':''));
  await page.evaluate(async({incremental,selectedRoot,restoreRoot,deepRestore})=>{
    if(selectedRoot||restoreRoot)localStorage.setItem('flex-media-studio.preferences.browser.folders.one',JSON.stringify([null,'archive','child-0']));
    if(deepRestore)localStorage.setItem('flex-media-studio.preferences.browser.folders.one',JSON.stringify([null,'chain',...Array.from({length:30},(_,i)=>'level-'+i),'archive','child-0']));
    if(restoreRoot||deepRestore)localStorage.setItem('flex-media-studio.preferences.browser.folders.one.height','80');
    window.folderCalls=[];window.folderWrites=[];window.failFolders=false;window.delayFolders=deepRestore?35:restoreRoot?80:0;window.folderActive=0;window.folderMaxActive=0;window.delayBrowse=0;window.delayDetails=0;
    const {mountMediaStudio}=await import('/studio.js');
    const base={mediaType:null,mimeType:null,size:null,versionId:null,updatedAt:'2026-11-12T10:00:00Z',revision:1,trashedAt:null};
    const folder=(id,name,hasChildFolders=false)=>({...base,id,name,kind:'folder',hasChildFolders});
    const children=Array.from({length:3000},(_,index)=>folder('child-'+index,'Child '+String(index).padStart(4,'0'),index===0));
    const roots=[...(deepRestore?[folder('chain','Deep chain',true)]:[]),folder('archive','Archive',true),folder('leaf','Leaf'),folder('other','Other')];
    const file={...base,id:'file',name:'Report.svg',kind:'file',mediaType:'image',mimeType:'image/svg+xml',thumbnailUrl:'/filament.svg'};
    window.app=mountMediaStudio(document.querySelector('#studio'),{syncUrl:true,theme:'light',transport:{
      ...(incremental?{folders:{
        async changes(){return {items:[],revision:1,nextRevision:1,hasMore:false,reset:false};},
        async context(_,id){if(window.delayDetails)await new Promise(resolve=>setTimeout(resolve,window.delayDetails));const item=[...roots,...children,folder('deep','Deep folder')].find(entry=>entry.id===id);return {folderId:id,root:{id:null},tail:id==='archive'?[item]:[roots[0],item],hasCollapsedAncestors:false,ancestorCursor:null,totalDepth:null,revision:1};},
        async ancestors(){return {items:[],nextCursor:null,revision:1};},
      }}:{}),
      async workspaces(){return {items:[{id:'one',name:'One',role:'owner'},{id:'two',name:'Two',role:'owner'}],canCreate:false};},
      async workspace(id){return {id,name:id==='one'?'One':'Two',role:'owner'};},
      async browse(workspace,query,cursor,signal){
        if(query.view!=='folders'){
          if(window.delayBrowse)await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,window.delayBrowse);signal.addEventListener('abort',()=>{clearTimeout(timer);reject(new DOMException('Aborted','AbortError'));},{once:true});});
          return {items:[file],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};
        }
        window.folderCalls.push({workspace,parent:query.parent,cursor,limit:query.limit});window.folderActive++;window.folderMaxActive=Math.max(window.folderMaxActive,window.folderActive);
        try{
          if(window.delayFolders)await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,window.delayFolders);signal.addEventListener('abort',()=>{clearTimeout(timer);reject(new DOMException('Aborted','AbortError'));},{once:true});});
          if(window.failFolders){window.failFolders=false;throw new Error('Temporary folder error');}
          const all=workspace==='two'?[folder('private','Second workspace')]:query.parent===null?roots:query.parent==='chain'?[folder('level-0','Level 00',true)]:query.parent?.startsWith('level-')?(Number(query.parent.slice(6))<29?[folder('level-'+(Number(query.parent.slice(6))+1),'Level '+String(Number(query.parent.slice(6))+1).padStart(2,'0'),true)]:[]):query.parent==='archive'?children:query.parent==='child-0'?[folder('deep','Deep folder')]:[];
          const start=Number(cursor??0),limit=query.limit??40;
          return {items:all.slice(start,start+limit),nextCursor:start+limit<all.length?String(start+limit):null,previousCursor:start?String(Math.max(0,start-limit)):null,hasMore:start+limit<all.length,canWrite:true};
        }finally{window.folderActive--;}
      },
      async details(workspace,id){if(window.delayDetails)await new Promise(resolve=>setTimeout(resolve,window.delayDetails));const entry=[...roots,...children,folder('deep','Deep folder')].find(entry=>entry.id===id);if(!entry)throw new Error('Missing');return {...entry,parentId:id==='deep'?'child-0':id.startsWith('child-')?'archive':null,metadata:{},createdAt:base.updatedAt};},
      async update(workspace,id,input){window.folderWrites.push({workspace,id,input});return {...file,revision:2};},
      async bulk(workspace,input){window.folderWrites.push({workspace,input});return {items:[{...file,revision:2}]};},
      async createFolder(workspace,parent,name){const created=folder('created',name);roots.push(created);return created;},
      async status(){return {items:[]};},
    }});
  },{incremental,selectedRoot,restoreRoot,deepRestore});
  await expect(page.locator('.fms-title-row h1')).toBeVisible();
  return {page,errors};
}
const disclosure=(page,name)=>page.locator('.fms-sidebar').getByRole('button',{name:name==='All files'?'Folder: All files':name,exact:true}).filter({has:page.locator('svg')}).and(page.locator('[aria-expanded]'));
test('sidebar loads children on disclosure, reuses cached pages and virtualizes cursor windows',async()=>{
  const {page,errors}=await open();
  try{
    assert.deepEqual(await page.evaluate(()=>window.folderCalls),[]);
    await disclosure(page,'All files').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await expect(tree.getByRole('button',{name:'Archive',exact:true}).first()).toBeVisible();
    assert.equal(await page.evaluate(()=>window.folderCalls.length),1);
    assert.equal(await tree.locator('[aria-expanded][aria-label=Leaf]').count(),0);
    await disclosure(page,'Archive').click();
    await expect(tree.getByRole('button',{name:'Child 0000',exact:true}).first()).toBeVisible();
    assert.equal(await page.evaluate(()=>window.folderCalls.length),2);
    assert.equal(await page.evaluate(()=>window.folderCalls.at(-1).limit),50);
    const panel=page.locator('.fms-sidebar-content');
    await expect(panel).toHaveAttribute('data-slot','scroll-shadow');
    await expect(tree).toHaveAttribute('data-slot','scroll-shadow');
    await expect(panel).toHaveCSS('scrollbar-width','none');
    await expect(tree).toHaveCSS('scrollbar-width','none');
    const rootName=page.locator('.fms-folder-root>.fms-nav');
    const folderName=tree.locator('.fms-sidebar-folder-name').first();
    for(const property of ['font-size','font-weight','line-height']){
      assert.equal(await folderName.evaluate((element,property)=>getComputedStyle(element).getPropertyValue(property),property),await rootName.evaluate((element,property)=>getComputedStyle(element).getPropertyValue(property),property));
    }
    assert.equal((await folderName.locator('svg').boundingBox()).width,(await rootName.locator('svg').boundingBox()).width);
    const row=tree.getByRole('treeitem').first();
    assert.ok((await row.boundingBox()).x>(await tree.boundingBox()).x,'row hover starts at its folder indentation');

    await disclosure(page,'Archive').click();await disclosure(page,'Archive').click();
    await expect(tree.getByRole('button',{name:'Child 0000',exact:true}).first()).toBeVisible();
    assert.equal(await page.evaluate(()=>window.folderCalls.length),2);
    await tree.focus();await page.keyboard.press('Home');await page.keyboard.press('ArrowDown');
    for(let index=0;index<17;index++){
      await tree.evaluate(element=>{element.scrollTop=element.scrollHeight;});
      await page.waitForTimeout(35);
    }
    await expect.poll(()=>page.evaluate(()=>window.folderCalls.some(call=>Number(call.cursor)>=160))).toBe(true);
    const viewportHeight=await tree.evaluate(node=>node.clientHeight);
    assert.ok(await tree.getByRole('treeitem').count()<=Math.ceil(viewportHeight/40)+10);
    assert.ok(await page.evaluate(()=>window.folderMaxActive)<=2);
    assert.ok(await page.evaluate(()=>window.folderCalls.length)<24,'pagination stops after the viewport leaves the edge. Calls: '+await page.evaluate(()=>window.folderCalls.length));
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
test('deep links reveal ancestors, folder clicks navigate and expanded branches survive reload',async()=>{
  const {page,errors}=await open({deep:true});
  try{
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await expect(tree.getByRole('treeitem',{name:'Deep folder',exact:true})).toHaveAttribute('aria-selected','true');
    assert.ok(await page.evaluate(()=>window.folderCalls.every(call=>[null,'archive','child-0'].includes(call.parent))));
    await tree.getByRole('button',{name:'Child 0001',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Child 0001');
    assert.match(page.url(),/fmsFolder=child-1/);
    assert.ok(await page.evaluate(()=>JSON.parse(localStorage.getItem('flex-media-studio.preferences.browser.folders.one')).includes('archive')));
    await page.getByRole('button',{name:'Account menu',exact:true}).click();
    await page.getByRole('menuitem',{name:'Two',exact:true}).click();
    await expect(page.getByRole('treeitem',{name:'Deep folder',exact:true})).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
test('sidebar retries failed pages and accepts a drag destination without navigation',async()=>{
  const {page,errors}=await open();
  try{
    await page.evaluate(()=>{window.failFolders=true;});
    await disclosure(page,'All files').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await tree.getByRole('button',{name:'Try again',exact:true}).click();
    await expect(tree.getByRole('button',{name:'Archive',exact:true}).first()).toBeVisible();
    const source=await page.getByRole('button',{name:'Preview: Report.svg',exact:true}).boundingBox();
    const target=await tree.getByRole('treeitem',{name:'Archive Archive',exact:true}).boundingBox();
    await page.mouse.move(source.x+40,source.y+40);await page.mouse.down();
    await page.mouse.move(target.x+40,target.y+18,{steps:8});
    await expect(tree.getByRole('treeitem',{name:'Archive Archive',exact:true})).toHaveAttribute('data-move-over','true');
    await expect(disclosure(page,'Archive')).toHaveAttribute('aria-expanded','true');
    await page.mouse.up();
    await expect.poll(()=>page.evaluate(()=>window.folderWrites.length)).toBe(1);
    assert.equal(await page.evaluate(()=>window.folderWrites[0].input.parent),'archive');
    assert.doesNotMatch(page.url(),/fmsFolder/);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('folder navigation keeps header controls mounted and their geometry fixed while metadata and files load',async()=>{
  const {page,errors}=await open();
  try{
    await disclosure(page,'All files').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await expect(tree.getByRole('button',{name:'Archive',exact:true}).first()).toBeVisible();
    const header=page.locator('.fms-library-header');
    const selectors=['.fms-title-row','.fms-title-right','.fms-toolbar','.fms-library-actions','.fms-toolbar-view'];
    const geometry=async()=>Promise.all(selectors.map(async selector=>{
      const box=await header.locator(selector).boundingBox();return {selector,x:box.x,y:box.y,width:box.width,height:box.height};
    }));
    const initial=await geometry();
    await header.getByRole('button',{name:'Layout',exact:true}).evaluate(element=>{window.originalLayoutButton=element;});
    await page.evaluate(()=>{window.delayBrowse=280;window.delayDetails=420;});
    await tree.getByRole('button',{name:'Archive',exact:true}).first().click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Archive');
    assert.deepEqual(await geometry(),initial);
    await expect(page.locator('.fms-scroll').getByRole('button',{name:'Preview: Report.svg',exact:true})).toHaveCount(0);
    for(let sample=0;sample<7;sample++){
      await page.waitForTimeout(80);
      assert.deepEqual(await geometry(),initial);
    }
    assert.equal(await header.getByRole('button',{name:'Layout',exact:true}).evaluate(element=>element===window.originalLayoutButton),true);
    await expect(page.locator('.fms-scroll').getByRole('button',{name:'Preview: Report.svg',exact:true})).toBeVisible();
    await tree.getByRole('button',{name:'Leaf',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Leaf');
    assert.deepEqual(await geometry(),initial);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('folder arrivals animate inner content only, prefetch ahead of the viewport, and respect reduced motion',async()=>{
  const {page,errors}=await open();
  try{
    await page.emulateMedia({reducedMotion:'no-preference'});
    await disclosure(page,'All files').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await expect(tree.getByRole('button',{name:'Archive',exact:true}).first()).toBeVisible();
    await page.evaluate(()=>{
      window.delayFolders=250;window.folderArrivalAnimations=[];
      const original=Element.prototype.animate;
      Element.prototype.animate=function(frames,options){if(this.classList.contains('fms-folder-row-content'))window.folderArrivalAnimations.push({key:this.parentElement.dataset.folderKey,position:getComputedStyle(this.parentElement).transform});return original.call(this,frames,options);};
    });
    await disclosure(page,'Archive').click();
    await expect(tree.locator('.fms-folder-row-loading')).toBeVisible();
    await expect(tree.getByRole('button',{name:'Child 0000',exact:true}).first()).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.folderArrivalAnimations.length)).toBeGreaterThan(0);
    assert.ok((await page.evaluate(()=>window.folderArrivalAnimations)).every(animation=>animation.position==='none'),'virtual row transforms stay untouched');
    await tree.evaluate(element=>{const edge=element.querySelector('[data-folder-key="next:archive"]');if(edge)throw new Error('Sentinel should not yet be rendered');element.scrollTop=51*40-element.clientHeight-8*40;});
    await expect.poll(()=>page.evaluate(()=>window.folderCalls.some(call=>call.parent==='archive'&&call.cursor==='50'))).toBe(true);
    assert.ok(await tree.getByRole('treeitem').count()<=Math.ceil(await tree.evaluate(element=>element.clientHeight)/40)+10);
    await page.emulateMedia({reducedMotion:'reduce'});
    await tree.evaluate(element=>{element.scrollTop=0;});
    await expect(disclosure(page,'Archive')).toBeVisible();
    await disclosure(page,'Archive').click();
    const count=await page.evaluate(()=>window.folderArrivalAnimations.length);
    await disclosure(page,'Archive').click();
    await expect(tree.getByRole('button',{name:'Child 0000',exact:true}).first()).toBeVisible();
    await page.waitForTimeout(220);
    assert.equal(await page.evaluate(()=>window.folderArrivalAnimations.length),count);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('clicking an already visible folder preserves its sidebar position while cold deep links still reveal selection',async()=>{
  const {page,errors}=await open();
  try{
    await disclosure(page,'All files').click();
    await disclosure(page,'Archive').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    const target=tree.getByRole('button',{name:'Child 0003',exact:true});
    await expect(target).toBeVisible();
    await page.evaluate(()=>{window.delayDetails=220;window.delayBrowse=150;});

    await expect.poll(()=>target.evaluate(element=>element.closest('.fms-folder-row-content').getAnimations().length)).toBe(0);
    const before={top:await tree.evaluate(element=>element.scrollTop),y:(await target.boundingBox()).y};
    const point=await target.boundingBox();
    await page.mouse.click(point.x+point.width/2,point.y+point.height/2);
    await expect(page.locator('.fms-title-row h1')).toHaveText('Child 0003');
    await expect(tree.getByRole('treeitem',{name:'Child 0003',exact:true})).toHaveAttribute('aria-selected','true');
    await page.waitForTimeout(700);
    assert.equal(await tree.evaluate(element=>element.scrollTop),before.top);
    assert.ok(Math.abs((await target.boundingBox()).y-before.y)<1,'clicked row stays under the pointer after metadata and path resolution');
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('focus during folder selection does not reload the tree when incremental synchronization is available',async()=>{
  const {page,errors}=await open({incremental:true});
  try{
    await disclosure(page,'All files').click();await disclosure(page,'Archive').click();
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    const target=tree.getByRole('button',{name:'Child 0003',exact:true});
    await expect(target).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.folderActive)).toBe(0);
    await expect.poll(()=>target.evaluate(e=>e.closest('.fms-folder-row-content').getAnimations().length)).toBe(0);
    const calls=await page.evaluate(()=>window.folderCalls.length);
    await target.evaluate(element=>{window.selectedFolderNode=element;window.delayDetails=250;window.delayFolders=200;});
    const point=await target.boundingBox();
    await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
    await page.mouse.click(point.x+point.width/2,point.y+point.height/2);
    await expect(page.locator('.fms-title-row h1')).toHaveText('Child 0003');
    await expect(tree.getByRole('treeitem',{name:'Child 0003',exact:true})).toHaveAttribute('aria-selected','true');
    await page.waitForTimeout(450);
    assert.equal(await page.evaluate(()=>window.folderCalls.length),calls,'unchanged hierarchy is not fetched again on focus');
    assert.equal(await target.evaluate(element=>element===window.selectedFolderNode),true,'selected row stays mounted');
    await expect(tree.locator('.fms-folder-row-loading')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('cold root-folder link remains visible after restored earlier branches shift its index',async()=>{
 const {page,errors}=await open({selectedRoot:true});
 try{
  const tree=page.getByRole('tree',{name:'Folder',exact:true}),selected=tree.getByRole('treeitem',{name:'Leaf',exact:true});
  await expect(selected).toHaveAttribute('aria-selected','true');
  await expect.poll(async()=>{const row=await selected.boundingBox(),box=await tree.boundingBox();return !!row&&row.y>=box.y&&row.y+row.height<=box.y+box.height;}).toBe(true);
  await expect.poll(()=>page.evaluate(()=>window.folderActive)).toBe(0);
  const row=await selected.boundingBox(),box=await tree.boundingBox();assert.ok(row.y>=box.y&&row.y+row.height<=box.y+box.height);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('breadcrumbs navigate to an ancestor by pointer as well as keyboard',async()=>{
  const {page,errors}=await open({deep:true,incremental:true});
  try{
    const trail=page.locator('.fms-folder-breadcrumbs');
    await expect(trail.getByRole('link',{name:'Archive',exact:true})).toBeVisible();
    await trail.getByRole('link',{name:'Archive',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Archive');
    assert.match(page.url(),/fmsFolder=archive/);
    await trail.getByRole('link',{name:'All files',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
    assert.doesNotMatch(page.url(),/fmsFolder/);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


for(const deepRestore of [false,true])test(`cold root restoration stays atomic with ${deepRestore?'thirty nested branches':'a short saved layout'}`,async()=>{
 const {page,errors}=await open({restoreRoot:true,incremental:true,deepRestore});
 try{
  const panel=page.locator('.fms-sidebar-folders');
  await expect(panel).not.toHaveAttribute('data-initializing','true');
  const tree=page.getByRole('tree',{name:'Folder',exact:true});
  const samples=await tree.evaluate(async element=>{
   const samples=[];
   for(let index=0;index<24;index++){
    await new Promise(requestAnimationFrame);
    const bounds=element.getBoundingClientRect();
    const rows=[...element.querySelectorAll('[data-folder-key]')].filter(row=>{const r=row.getBoundingClientRect();return r.bottom>bounds.top&&r.top<bounds.bottom;});
    samples.push({keys:rows.map(row=>row.dataset.folderKey),loading:rows.some(row=>row.querySelector('.fms-folder-row-loading'))});
   }
   return samples;
  });
  assert.ok(samples.every(sample=>!sample.loading),'no visible progressive loaders after initial reveal');
  assert.ok(samples.every(sample=>JSON.stringify(sample.keys)===JSON.stringify(samples[0].keys)),'visible folder window remains stable after reveal');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('upward wheel scrolling across older cursor pages preserves logical position without entry animations',async()=>{
 const {page,errors}=await open();
 try{
  await disclosure(page,'All files').click();await disclosure(page,'Archive').click();
  const tree=page.getByRole('tree',{name:'Folder',exact:true});
  await expect(tree.getByRole('button',{name:'Child 0000',exact:true}).first()).toBeVisible();
  for(let i=0;i<9;i++){
   await tree.evaluate(el=>{el.scrollTop=el.scrollHeight;});
   await page.waitForTimeout(40);
  }
  await expect.poll(()=>page.evaluate(()=>window.folderActive)).toBe(0);
  await page.evaluate(()=>{window.delayFolders=70;window.scrollEntryAnimations=0;const original=Element.prototype.animate;Element.prototype.animate=function(frames,options){if(this.classList.contains('fms-folder-row-content'))window.scrollEntryAnimations++;return original.call(this,frames,options);};});
  const box=await tree.boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
  const position=()=>tree.evaluate(el=>{
   const top=el.getBoundingClientRect().top;
   const row=[...el.querySelectorAll('[data-folder-key^="child-"]')].find(row=>row.getBoundingClientRect().bottom>top);
   return row?Number(row.dataset.folderKey.slice(6))*40-(row.getBoundingClientRect().top-top):null;
  });
  const before=await position();let previous=before;
  for(let i=0;i<95;i++){
   await page.mouse.wheel(0,-50);await page.waitForTimeout(18);
   const current=await position();
   if(current!==null&&previous!==null)assert.ok(current<=previous+1,`upward scroll reversed from ${previous} to ${current}`);
   previous=current;
  }
  assert.ok(previous<before-2500,'the test traversed older cursor pages');
  assert.equal(await page.evaluate(()=>window.scrollEntryAnimations),0,'paging never animates tree entries');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('reloading a later root folder then scrolling into the restored deep branch does not rebuild its prefix',async()=>{
 const {page,errors}=await open({selectedRoot:'archive',deepRestore:true});
 try{
  await expect(page.locator('.fms-sidebar-folders')).not.toHaveAttribute('data-initializing','true');
  const tree=page.getByRole('tree',{name:'Folder',exact:true});
  const box=await tree.boundingBox();await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
  const reads=await page.evaluate(()=>window.folderCalls.filter(call=>call.parent==='chain'||call.parent?.startsWith('level-')).length);
  assert.ok(reads>=30,'restored expanded topology is ready above the selected folder');
  for(let step=0;step<24;step++){
   await page.mouse.wheel(0,-60);await page.waitForTimeout(18);
   const pending=await tree.evaluate(el=>{const r=el.getBoundingClientRect();return [...el.querySelectorAll('.fms-folder-row-loading')].some(node=>{const row=node.getBoundingClientRect();return row.bottom>r.top&&row.top<r.bottom;});});
   assert.equal(pending,false,'scrolling upward does not expose a progressively reconstructed branch');
  }
  assert.equal(await page.evaluate(()=>window.folderCalls.filter(call=>call.parent==='chain'||call.parent?.startsWith('level-')).length),reads);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
