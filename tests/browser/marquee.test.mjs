import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let browser,server,origin;
before(async()=>{
 const assets=new Map(await Promise.all(['studio.js','studio.css','studio-mark.svg','filament.svg'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
 server=createServer((req,res)=>{const path=new URL(req.url,'http://localhost').pathname;res.setHeader('Content-Type',path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/html');res.end(assets.get(path)??'<div id="studio" style="height:100vh"></div>')});await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(r=>server?.close(r));});
test('dragging on empty gallery space selects intersecting virtual cards without opening them',async()=>{
 const page=await browser.newPage({viewport:{width:1400,height:850}});try{
  await page.goto(origin);
  await page.evaluate(async()=>{const {mountMediaStudio}=await import('/studio.js');const workspace={id:'w',name:'Test',role:'owner'};const items=Array.from({length:80},(_,i)=>({id:'f'+i,name:'File '+i,kind:'file',mediaType:'image',mimeType:'image/png',size:1,revision:1,updatedAt:new Date().toISOString(),trashedAt:null}));const transport={workspaces:async()=>({items:[workspace],canCreate:false}),browse:async()=>({items,nextCursor:null,previousCursor:null,hasMore:false,canWrite:true}),bulk:async()=>({items:[]}),neighbors:async()=>({items:[],nextCursor:null,previousCursor:null,hasMore:false}),status:async()=>({items:[]}),details:async()=>items[0],contentUrl:()=>''};mountMediaStudio(document.querySelector('#studio'),{transport})});
  const studio=page.locator('#studio');const area=studio.locator('.fms-scroll');await expect(studio.locator('[data-select-entry]')).not.toHaveCount(0);
  const box=await area.boundingBox();const card=await studio.locator('[data-select-entry]').first().boundingBox();
  await page.mouse.move(box.x+2,card.y+5);await page.mouse.down();await page.mouse.move(card.x+card.width+4,card.y+card.height-5,{steps:8});await page.mouse.up();
  await expect(studio.getByRole('group',{name:'Selection'})).toBeVisible();await expect(studio.locator('.fms-entry.is-selected')).not.toHaveCount(0);
  assert.ok(await studio.locator('.fms-entry.is-selected').count()<=100);
  await studio.getByRole('button',{name:'Clear selection',exact:true}).click();await expect(studio.locator('.fms-entry.is-selected')).toHaveCount(0);
 }finally{await page.close()}
});
