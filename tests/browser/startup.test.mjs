import {localeAssets} from './locale-assets.mjs';
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let browser, server, origin, startupStyles;
const document = `<!doctype html><html><body style="margin:0"><div id="studio" style="height:100vh"></div>
<script type="module">
import {mountMediaStudio} from '/studio.js';
const workspace = {id:'demo',name:'Demo',role:'owner'};
window.calls = [];
const wait = name => new Promise(resolve => {window['release'+name] = resolve; window.calls.push(name);});
window.instance = mountMediaStudio(document.querySelector('#studio'), {
  transport:{
    async workspaces(){await wait('Workspaces');return {items:[workspace],canCreate:false};},
    async browse(){await wait('Browse');return {items:[],canWrite:true,nextCursor:null,previousCursor:null,hasMore:false};},
    async createFolder(){throw Error('Not used');},async upload(){throw Error('Not used');},contentUrl(){throw Error('Not used');}
  }
});
</script></body></html>`;
before(async () => {
  startupStyles = await readFile(new URL('../../packages/react/styles/startup.css', import.meta.url), 'utf8');
  const assets = new Map(await Promise.all([
    ['studio.js','text/javascript'],['studio.css','text/css'],['filament.svg','image/svg+xml'],['studio-mark.svg','image/svg+xml'],
  ].map(async ([name,type])=>['/'+name,{type,body:await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))}])));
  for(const [name,value]of await localeAssets({raw:false}))assets.set(name,value);
  server = createServer((request,response)=>{
    const url = new URL(request.url,'http://localhost');
    const asset = assets.get(url.pathname);
    const html = url.searchParams.has('prerendered') ? document
      .replace('<script type="module">', '<style>'+startupStyles+'</style><div id="pre-rendered-startup" class="fms-startup" role="status" aria-label="Loading your library…"><span class="fms-startup-spinner"></span></div><script type="module">')
      .replace('transport:{', 'startupElement:document.querySelector("#pre-rendered-startup"),transport:{') : document;
    response.writeHead(200,{'Content-Type':asset?.type??'text/html'});
    response.end(asset?.body??html);
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});

async function finishStartup(page) {
  const content = page.locator('.fms-content');
  await expect.poll(()=>page.evaluate(()=>window.calls)).toEqual(['Workspaces']);
  await expect(content).toHaveCSS('visibility','hidden');
  await expect(content).toHaveAttribute('inert','');
  await expect(page.locator('.fms-startup')).toBeVisible();
  await expect(page.locator('.fms-header')).not.toBeVisible();
  await page.evaluate(()=>window.releaseWorkspaces());
  await expect.poll(()=>page.evaluate(()=>window.calls)).toEqual(['Workspaces','Browse']);
  await expect(content).toHaveCSS('visibility','hidden');
  await expect(page.locator('.fms-startup')).toBeVisible();
  await page.evaluate(()=>window.releaseBrowse());
  await expect(content).toHaveCSS('visibility','visible');
  await expect(content).not.toHaveAttribute('inert');
  await expect(page.locator('.fms-startup')).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Create',exact:true})).toBeVisible();
  const samples = await page.locator('.fms-header').evaluate(async header=>{
    const root=header.closest('.fms-shell');
    const result=[];
    for(let frame=0;frame<12;frame++){
      await new Promise(requestAnimationFrame);
      result.push(['.fms-header','.fms-title-row','.fms-soft-action','.fms-header-search'].map(selector=>{
        const {x,y,width,height}=root.querySelector(selector).getBoundingClientRect();
        return {x,y,width,height};
      }));
    }
    return result;
  });
  for(const frame of samples)assert.deepEqual(frame,samples[0]);
  assert.ok(samples[0][2].height<=36,JSON.stringify(samples[0][2]));
  return samples[0];
}

test('initial opening and reload reveal complete measured controls without intermediate header sizes', async()=>{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  try{
    await page.goto(origin);
    const first=await finishStartup(page);
    await page.reload();
    assert.deepEqual(await finishStartup(page),first);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('a failed first listing reveals recovery controls instead of keeping the shell hidden', async()=>{
  const page=await browser.newPage({viewport:{width:390,height:844}});
  try{
    await page.goto(origin);
    await expect.poll(()=>page.evaluate(()=>window.calls)).toEqual(['Workspaces']);
    await page.evaluate(()=>{
      window.instance.destroy();
    });
    await page.evaluate(async()=>{
      const {mountMediaStudio}=await import('/studio.js');
      window.instance=mountMediaStudio(document.querySelector('#studio'),{transport:{
        async workspaces(){return {items:[{id:'w',name:'Demo',role:'owner'}],canCreate:false};},
        async browse(){throw Error('Listing unavailable');},async createFolder(){},async upload(){},contentUrl(){return '';}
      }});
    });
    await expect(page.locator('.fms-content')).toHaveCSS('visibility','visible');
    await expect(page.getByRole('alert')).toContainText('Listing unavailable');
    await expect(page.getByRole('button',{name:'Try again',exact:true})).toBeVisible();
  }finally{await page.close();}
});

test('the first-paint spinner keeps its animation through module and stylesheet loading, then leaves before content enters', async()=>{
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  let releaseModule;
  const moduleReady=new Promise(resolve=>{releaseModule=resolve;});
  await page.route(origin+'/studio.js',async route=>{await moduleReady;await route.continue();});
  try {
    await page.goto(origin+'/?prerendered=1',{waitUntil:'commit'});
    await expect(page.locator('#pre-rendered-startup')).toBeVisible();
    const first=await page.evaluate(()=>{
      window.firstStartup=document.querySelector('#pre-rendered-startup');
      const spinner=window.firstStartup.querySelector('span');
      window.firstSpin=spinner.getAnimations()[0];
      const box=spinner.getBoundingClientRect();
      return {x:box.x+box.width/2,y:box.y+box.height/2,duration:window.firstSpin.effect.getTiming().duration};
    });
    assert.deepEqual(first,{x:720,y:450,duration:1000});
    releaseModule();
    await expect.poll(()=>page.evaluate(()=>window.calls)).toEqual(['Workspaces']);
    assert.equal(await page.evaluate(()=>document.querySelector('#pre-rendered-startup')===window.firstStartup&&window.firstStartup.querySelector('span').getAnimations()[0]===window.firstSpin),true);
    await page.evaluate(()=>window.releaseWorkspaces());
    await expect.poll(()=>page.evaluate(()=>window.calls)).toEqual(['Workspaces','Browse']);
    const frames=await page.evaluate(async()=>{
      window.releaseBrowse();
      const samples=[];
      for(let i=0;i<25;i++){
        await new Promise(requestAnimationFrame);
        const content=document.querySelector('.fms-host').shadowRoot.querySelector('.fms-content');
        samples.push({visible:getComputedStyle(content).visibility==='visible',opacity:Number(getComputedStyle(content).opacity),loader:!!document.querySelector('#pre-rendered-startup')});
      }
      return samples.filter(frame=>frame.visible);
    });
    assert.ok(frames.length>0);
    assert.ok(frames.every(frame=>!frame.loader));
    assert.ok(frames.some(frame=>frame.opacity>0&&frame.opacity<1));
    assert.equal(frames.at(-1).opacity,1);
    assert.ok(frames.every((frame,i)=>i===0||frame.opacity>=frames[i-1].opacity));
  } finally {
    releaseModule();
    await page.close();
  }
});
