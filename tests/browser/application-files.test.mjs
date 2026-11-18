import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let server, browser, origin;
before(async () => {
  const assets = new Map(await Promise.all(['studio.js','studio.css','studio-mark.svg','filament.svg'].map(async name => [name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
  const html = `<!doctype html><html><body style="margin:0"><div id="studio" style="height:100dvh"></div><script type="module">
  import {mountMediaStudio} from '/studio.js';
  window.calls={workspaces:0,browse:[],status:[]};
  const dynamicThumbnails=new URLSearchParams(location.search).get('thumbnails')==='1';
  const sources=[{id:'avatars',name:'Application avatars',type:'disk',readOnly:true},{id:'spatie',name:'Spatie',type:'spatie',readOnly:true}];
  const files=Array.from({length:1200},(_,i)=>({id:'asset-'+i,name:'Photo '+String(i).padStart(4,'0')+'.png',kind:'file',readOnly:true,mimeType:'image/png',size:1024,versionId:'v1',revision:1,mediaType:'image',updatedAt:'2026-11-08T00:00:00Z',createdAt:'2026-11-08T00:00:00Z',trashedAt:null,parentId:null,metadata:{},thumbnailState:dynamicThumbnails?'queued':'none',previewState:'ready',thumbnailUrl:null,previewUrl:'/filament.svg',downloadUrl:null}));
  const transport={
    applicationFiles:{async thumbnailStatus(ids){window.calls.status.push(ids);return {items:ids.map(id=>({...files.find(file=>file.id===id),thumbnailState:'ready',thumbnailUrl:'/filament.svg'}))};},async sources(){return {enabled:true,canDownload:false,items:sources};},async browse(source,query,cursor){window.calls.browse.push({source,parent:query.parent,cursor});const start=Number(cursor??0);return {items:files.slice(start,start+60),nextCursor:start+60<files.length?String(start+60):null,previousCursor:start>0?String(Math.max(0,start-60)):null,hasMore:start+60<files.length,canWrite:false,readOnly:true};},async details(_,id){return files.find(file=>file.id===id);},async neighbors(_,id,query,direction){const index=files.findIndex(file=>file.id===id);return {items:direction==='next'?files.slice(index+1,index+61):files.slice(Math.max(0,index-60),index),hasMore:false,nextCursor:null,previousCursor:null,canWrite:false};},contentUrl(){return '/filament.svg';}},
    async workspaces(){window.calls.workspaces++;return {items:[{id:'main',name:'Main library',role:'viewer',permissions:{'members.view':true,'settings.view':true}},{id:'secondary',name:'Second library',role:'viewer',permissions:{'members.view':true,'settings.view':true}}],canCreate:false};},async browse(){return {items:[],hasMore:false};},async details(){throw Error('unused');},async createFolder(){throw Error('unused');},async upload(){throw Error('unused');},contentUrl(){return '';}
  };
  mountMediaStudio(document.querySelector('#studio'),{transport,syncUrl:true});
  </script></body></html>`;
  server=createServer((request,response)=>{
    const path=new URL(request.url,'http://localhost').pathname.slice(1);
    if(assets.has(path)){response.setHeader('Content-Type',path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/javascript');response.end(assets.get(path));}
    else{response.setHeader('Content-Type','text/html');response.end(html);}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  origin='http://127.0.0.1:'+server.address().port;
  browser=await chromium.launch({headless:true});
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});

test('application catalog loads navigation once, restores URL and virtualizes read-only files',async()=>{
  const page=await browser.newPage({viewport:{width:1100,height:720}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.goto(origin+'/?fmsView=application&fmsWorkspace=ignored');
    await expect(page.getByRole('heading',{name:'App assets',exact:true})).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(0);
    await expect(page.locator('.fms-application-source-filter')).toContainText('All sources');
    await expect(page.getByRole('button',{name:'Preview: Photo 0000.png',exact:true})).toBeVisible();
    assert.equal(await page.evaluate(()=>window.calls.workspaces),1);
    await page.locator('.fms-application-source-filter').getByRole('button').click();
    await page.getByRole('option',{name:'Spatie',exact:true}).click();
    assert.equal(new URL(page.url()).searchParams.get('fmsSource'),'spatie');
    await page.locator('.fms-application-source-filter').getByRole('button').click();
    await page.getByRole('option',{name:'Application avatars',exact:true}).click();
    await expect(page.getByRole('button',{name:'Upload',exact:true})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'New folder',exact:true})).toHaveCount(0);
    await page.locator('.fms-scroll').evaluate(element=>{element.scrollTop=element.scrollHeight;});
    await expect.poll(()=>page.evaluate(()=>window.calls.browse.length)).toBeGreaterThan(1);
    assert.ok(await page.locator('.fms-entry').count()<60);
    await page.reload();
    await expect(page.getByRole('button',{name:'Preview: Photo 0000.png',exact:true})).toBeVisible();
    assert.equal(await page.evaluate(()=>window.calls.workspaces),1);
    await page.getByRole('button',{name:'Layout',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'List view',exact:true}).click();
    await expect(page.locator('.fms-scroll')).toHaveClass(/is-list/);
    await page.getByRole('button',{name:'Layout',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'Large grid',exact:true}).click();
    await expect(page.locator('.fms-scroll')).toHaveClass(/is-large-grid/);
    await page.getByRole('button',{name:'Preview: Photo 0000.png',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Photo 0000.png',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Move to trash',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Photo 0000.png',exact:true})).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('switching Files and App assets keeps the existing navigation nodes and geometry stable',async()=>{
  const page=await browser.newPage({viewport:{width:1100,height:720}});
  try {
    await page.goto(origin+'/?fmsWorkspace=secondary');
    await expect(page.getByRole('button',{name:'App assets',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Main library',exact:false})).toBeVisible();
    const nodes=await page.locator('.fms-rail,.fms-sidebar,.fms-header').elementHandles();
    const boxes=await Promise.all(nodes.map(node=>node.boundingBox()));
    const menu=await page.locator('.fms-sidebar').evaluate(node=>Array.from(node.querySelectorAll('.fms-nav')).map(item=>({text:item.textContent,y:item.getBoundingClientRect().y,height:item.getBoundingClientRect().height})));
    for(let i=0;i<3;i++) {
      await page.getByRole('button',{name:'App assets',exact:true}).click();
      await expect(page.getByRole('button',{name:'Preview: Photo 0000.png',exact:true})).toBeVisible();
      assert.equal(await page.locator('.fms-rail').evaluate((node,old)=>node===old,nodes[0]),true);
      assert.equal(await page.locator('.fms-sidebar').evaluate((node,old)=>node===old,nodes[1]),true);
      assert.equal(await page.locator('.fms-header').evaluate((node,old)=>node===old,nodes[2]),true);
      const nextMenu=await page.locator('.fms-sidebar').evaluate(node=>Array.from(node.querySelectorAll('.fms-nav')).map(item=>({text:item.textContent,y:item.getBoundingClientRect().y,height:item.getBoundingClientRect().height})));
      assert.deepEqual(nextMenu,menu);
      assert.deepEqual(await Promise.all(nodes.map(node=>node.boundingBox())),boxes);
      await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'All files',exact:true}).click();
      await expect(page.getByRole('heading',{name:'All files',exact:true})).toBeVisible();
      assert.equal(new URL(page.url()).searchParams.get('fmsWorkspace'),'secondary');
      await expect(page.getByRole('button',{name:'Second library',exact:true})).toHaveAttribute('aria-current','page');
    }
    assert.equal(await page.evaluate(()=>window.calls.workspaces),1);
  } finally {await page.close();}
});


test('application thumbnails are requested in viewport batches and ready results reach the shared grid',async()=>{
  const page=await browser.newPage({viewport:{width:1100,height:720}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.goto(origin+'/?fmsView=application&thumbnails=1');
    await expect(page.getByRole('heading',{name:'App assets',exact:true})).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.calls.status.length),{timeout:12000}).toBeGreaterThan(0);
    await expect(page.locator('.fms-entry img').first()).toBeVisible();
    const first=await page.evaluate(()=>window.calls.status[0]);
    assert.ok(first.length>0&&first.length<60);
    assert.equal(new Set(first).size,first.length);
    await page.locator('.fms-scroll').evaluate(element=>{element.scrollTop=element.scrollHeight;});
    await expect.poll(()=>page.evaluate(()=>window.calls.status.flat().some(id=>Number(id.split('-')[1])>=60)),{timeout:15000}).toBe(true);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
