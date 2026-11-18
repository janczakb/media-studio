import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let browser,server,origin;
before(async()=>{
  const assets=new Map(await Promise.all(['studio.js','studio.css','filament.svg'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
  for(const [name,body] of await localeAssets({raw:true})) assets.set('/'+name,body);
  server=createServer((request,response)=>{const path=new URL(request.url,'http://localhost').pathname;response.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/html');response.end(assets.get(path)??'<!doctype html><body style="margin:0"><div id="studio" style="height:100vh"></div>');});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
test('a 10,000-level deep link opens a contextual root with bounded ancestor requests and DOM',async()=>{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(origin+'/?fmsWorkspace=one&fmsFolder=n9999');
  await page.evaluate(async()=>{
    window.navigationCalls=[];
    const {mountMediaStudio}=await import('/studio.js');
    const folder=n=>({id:'n'+n,name:'Folder '+n,kind:'folder',hasChildFolders:true,mediaType:null,mimeType:null,size:null,versionId:null,updatedAt:'2026-11-12T10:00:00Z',revision:1,trashedAt:null});
    window.app=mountMediaStudio(document.querySelector('#studio'),{syncUrl:true,theme:'light',transport:{
      async workspaces(){return {items:[{id:'one',name:'One',role:'owner'}],canCreate:false};},
      async workspace(){return {id:'one',name:'One',role:'owner'};},
      folders:{
        async context(_,id){window.navigationCalls.push({kind:'context',id});const n=Number(id.slice(1));return {folderId:id,root:{id:null},tail:[n-3,n-2,n-1,n].map(folder),hasCollapsedAncestors:true,ancestorCursor:String(n-4),totalDepth:null,revision:1};},
        async ancestors(_,id,cursor,limit){window.navigationCalls.push({kind:'ancestors',id,cursor,limit});const start=Number(cursor);return {items:Array.from({length:Math.min(limit,start+1)},(_,i)=>folder(start-i)),nextCursor:start>=limit?String(start-limit):null,revision:1};},
      },
      async browse(_,query){window.navigationCalls.push({kind:'browse',view:query.view,parent:query.parent});return {items:query.view==='folders'?[folder(10000)]:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};},
      async details(_,id){window.navigationCalls.push({kind:'details',id});const n=Number(id.slice(1));return {...folder(n),parentId:'n'+(n-1),metadata:{},createdAt:'2026-11-12T10:00:00Z'};},
      async neighbors(){return {items:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};},
      async status(){return {items:[]};},
      contentUrl(){return '/content';},
    }});
  });
  await expect(page.getByRole('heading',{name:'Folder 9999',exact:true})).toBeVisible();
  const tree=page.getByRole('tree',{name:'Folder',exact:true});
  await expect(tree.getByRole('button',{name:'Folder 10000',exact:true}).first()).toBeVisible();
  const calls=await page.evaluate(()=>window.navigationCalls);
  assert.equal(calls.filter(call=>call.kind==='context').length,1);
  assert.deepEqual(calls.filter(call=>call.kind==='browse'&&call.view==='folders').map(call=>call.parent),['n9999']);
  assert.ok(calls.filter(call=>call.kind==='details').length<=1);
  await page.locator('.fms-breadcrumb-gap').click();
  const popup=page.locator('.fms-ancestor-popover');
  await expect(popup.getByRole('button',{name:'Folder 9995',exact:true})).toBeVisible();
  const scroll=popup.locator('.fms-ancestor-scroll');
  for(let i=0;i<7;i++){await scroll.evaluate(node=>{node.scrollTop=node.scrollHeight;});await page.waitForTimeout(45);}
  assert.ok(await page.evaluate(()=>window.navigationCalls.filter(call=>call.kind==='ancestors').length)>=4);
  assert.ok(await popup.locator('.fms-ancestor-option').count()<=16);
  const search=popup.getByRole('searchbox');
  await search.fill('not a folder');
  await expect(popup.getByText('No folders found.',{exact:true})).toBeVisible();
  const before=await page.evaluate(()=>window.navigationCalls.length);
  await page.waitForTimeout(100);
  assert.equal(await page.evaluate(()=>window.navigationCalls.length),before,'filtering loaded ancestors must not drain remote pages');
  await page.keyboard.press('Escape');
  await expect(popup).toBeHidden();
  assert.deepEqual(errors,[]);
  await page.close();
});

test('right-hand navigation through restored contextual branches keeps visible loaders moving and DOM bounded',async()=>{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(origin+'/?fmsWorkspace=one&fmsFolder=n10');
  await page.evaluate(async()=>{
    localStorage.setItem('flex-media-studio.preferences.browser.folders.one',JSON.stringify([null,...Array.from({length:31},(_,i)=>'n'+i)]));
    window.treeReads=[];
    const {mountMediaStudio}=await import('/studio.js');
    const folder=n=>({id:'n'+n,name:'Folder '+n,kind:'folder',hasChildFolders:n<35,mediaType:null,mimeType:null,size:null,versionId:null,updatedAt:'2026-11-12T10:00:00Z',revision:1,trashedAt:null});
    window.app=mountMediaStudio(document.querySelector('#studio'),{syncUrl:true,theme:'light',transport:{
      async workspaces(){return {items:[{id:'one',name:'One',role:'owner'}],canCreate:false};},async workspace(){return {id:'one',name:'One',role:'owner'};},
      folders:{async context(_,id){const n=Number(id.slice(1));return {folderId:id,root:{id:null},tail:[n-3,n-2,n-1,n].map(folder),hasCollapsedAncestors:true,ancestorCursor:null,totalDepth:null,revision:1};},async ancestors(){return {items:[],nextCursor:null,revision:1};}},
      async browse(_,query){const n=Number(query.parent?.slice(1)??0);if(query.view==='folders'){window.treeReads.push(n);await new Promise(resolve=>setTimeout(resolve,35));}return {items:n<35?[folder(n+1)]:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};},
      async details(_,id){const n=Number(id.slice(1));return {...folder(n),parentId:'n'+(n-1),metadata:{},createdAt:'2026-11-12T10:00:00Z'};},async status(){return {items:[]};},async neighbors(){return {items:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true};},contentUrl(){return '/content';},
    }});
  });
  try{
    const tree=page.getByRole('tree',{name:'Folder',exact:true});
    await expect(tree.locator('.fms-sidebar-folder-name').filter({hasText:'Folder 15'})).toBeVisible();
    for(let n=11;n<=18;n++){
      await page.locator('.fms-scroll').getByRole('button',{name:'Folder: Folder '+n,exact:true}).click();
      await expect(page.getByRole('heading',{name:'Folder '+n,exact:true})).toBeVisible();
      await expect(tree.locator('.fms-sidebar-folder-name').filter({hasText:'Folder '+(n+4)})).toBeVisible();
      const bounds=await tree.boundingBox();assert.ok(await tree.getByRole('treeitem').count()<=Math.ceil(bounds.height/40)+9);
      assert.ok(await page.evaluate(()=>window.treeReads.length)<100,'context changes must not scan the expanded tree');
    }
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
