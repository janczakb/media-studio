import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let server,browser,origin;
before(async()=>{
 const assets=new Map(await Promise.all(['studio.js','studio.css','studio-mark.svg','filament.svg','api-usage-chart.js'].map(async name=>[name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
 for(const [name,value] of await localeAssets({raw:false})) assets.set(name.replace(/^\//,''),value.body);
 server=createServer((req,res)=>{const path=new URL(req.url,'http://localhost').pathname.slice(1);if(assets.has(path)){res.setHeader('Content-Type',path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/javascript');res.end(assets.get(path));return;}
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><body style="margin:0"><div id="studio" style="height:100dvh"></div><script type="module">
 import {mountMediaStudio} from '/studio.js';
 window.calls={load:0,create:0,revoke:0};let items=[];
 const transport={async workspaces(){return {items:[{id:'main',name:'Main',role:'owner'}],canCreate:false};},async browse(){return {items:[],hasMore:false};},async aiInfo(){return {api:{enabled:true,canUse:true},mcp:{installed:true,enabled:false,canUse:false,endpoint:null,authentication:'managed',tools:[]},features:{imageTags:{implemented:true,enabled:false}}};},apiAccess:{
 async overview(){window.calls.load++;return {enabled:true,canManage:true,endpoint:'https://studio.example/rest/v1',workspace:'main',requestsPerMinute:120,maxDays:90,usageCacheSeconds:10,items:[...items],usage:Array.from({length:30},(_,i)=>({date:'2026-11-'+String(i+1).padStart(2,'0'),requests:i===29?8:0,successes:i===29?6:0,errors:i===29?2:0,durationMs:0}))};},
 async create(workspace,input){window.calls.create++;window.tokenInput=input;const item={id:'token1',name:input.name,scope:input.scope,status:'active',expiresAt:'2026-12-01T00:00:00Z',createdAt:'2026-11-01T00:00:00Z',lastUsedAt:null,suffix:'abcd'};items=[item];return {item,token:'fixture-one-time-token'};},
 async revoke(){window.calls.revoke++;items=items.map(i=>({...i,status:'revoked'}));return {revoked:true};}
 }};mountMediaStudio(document.querySelector('#studio'),{transport,syncUrl:true});
 </script></body></html>`);});await new Promise(r=>server.listen(0,'127.0.0.1',r));origin='http://127.0.0.1:'+server.address().port;browser=await chromium.launch({headless:true});
});
after(async()=>{await browser?.close();await new Promise(r=>server.close(r));});
test('API panel loads lazily, selectors work inside its modal, secret is one-time and revoke requires confirmation',async()=>{
 const page=await browser.newPage({viewport:{width:1200,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));try{
 await page.goto(origin+'/?fmsView=ai');await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();assert.equal(await page.evaluate(()=>window.calls.load),0);
 await page.getByRole('tab',{name:'API & SDK',exact:true}).click();await expect(page.getByRole('heading',{name:'API usage',exact:true})).toBeVisible();await expect(page.locator('.recharts-surface')).toBeVisible();await expect(page.locator('[data-empty-kind=tokens]')).toBeVisible();
 assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'Studio must not create a second document scrollbar');
 const railTop=await page.locator('.fms-rail').evaluate(e=>e.getBoundingClientRect().top);
 const titleTop=await page.getByRole('heading',{name:'AI',exact:true}).evaluate(e=>e.getBoundingClientRect().top);
 await page.locator('.fms-ai-page').evaluate(e=>{e.scrollTop=400;});
 assert.ok(await page.getByRole('heading',{name:'AI',exact:true}).evaluate(e=>e.getBoundingClientRect().top)<titleTop-100);
 assert.equal(await page.locator('.fms-rail').evaluate(e=>e.getBoundingClientRect().top),railTop);
 assert.equal(await page.evaluate(()=>window.scrollY),0);
 await page.mouse.move(1000,650);await page.mouse.wheel(0,2000);await expect.poll(()=>page.locator('.fms-ai-page').evaluate(e=>e.scrollTop)).toBeGreaterThan(300);
 assert.equal(await page.evaluate(()=>window.scrollY),0);
 assert.equal(await page.locator('.fms-rail').evaluate(e=>e.getBoundingClientRect().top),railTop);
 assert.equal(await page.locator('.fms-ai-panel').filter({has:page.locator('.fms-api-sdk')}).evaluate(e=>getComputedStyle(e).overflowY),'visible');
 await page.locator('.fms-ai-page').evaluate(e=>{e.scrollTop=0;});
 await page.getByRole('button',{name:'Create token',exact:true}).click();await page.getByRole('textbox',{name:'Name*',exact:true}).fill('Worker');
 await page.getByRole('button',{name:'Read only Access',exact:true}).click();await page.getByRole('option',{name:'Read & write',exact:true}).click();
 await page.getByRole('button',{name:'30 days Expires after',exact:true}).click();await page.getByRole('option',{name:'7 days',exact:true}).click();
 await page.getByRole('dialog').getByRole('button',{name:'Create token',exact:true}).click();await expect(page.getByRole('heading',{name:'Save your token'})).toBeVisible();assert.deepEqual(await page.evaluate(()=>window.tokenInput),{name:'Worker',scope:'write',days:7});
 await expect(page.getByRole('textbox',{name:'Token',exact:true})).toHaveValue('fixture-one-time-token');await page.getByRole('button',{name:'Done',exact:true}).click();await expect(page.getByRole('textbox',{name:'Token',exact:true})).toHaveCount(0);
 await page.getByRole('button',{name:'Revoke',exact:true}).click();assert.equal(await page.evaluate(()=>window.calls.revoke),0);await page.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(await page.evaluate(()=>window.calls.revoke),0);
 await page.getByRole('button',{name:'Revoke',exact:true}).click();await page.getByRole('button',{name:'Revoke token',exact:true}).click();await expect(page.locator('.fms-api-token-table').getByText('Revoked',{exact:true})).toBeVisible();
 for(const width of [390,768,1200]){
  await page.setViewportSize({width,height:900});
  await expect.poll(()=>page.locator('.fms-ai-page').evaluate(e=>e.scrollWidth>e.clientWidth+1)).toBe(false);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'Host document stays within viewport after resizing');
 }

 assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
