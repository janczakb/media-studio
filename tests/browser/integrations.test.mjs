import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let server,browser,origin;
before(async()=>{
  const assets=new Map(await Promise.all(['studio.js','studio.css','studio-mark.svg'].map(async name=>[name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
  for(const [name,value]of await localeAssets({raw:true}))assets.set(name,value);
  server=createServer((req,res)=>{
    const path=new URL(req.url,'http://localhost').pathname.slice(1);
    if(assets.has(path)){res.setHeader('Content-Type',path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/javascript');res.end(assets.get(path));return;}
    res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><body style="margin:0"><div id="studio" style="height:100dvh"></div><script type="module">
      import {mountMediaStudio} from '/studio.js';
      const params=new URLSearchParams(location.search);window.calls={overview:0,browse:0};
      const records={};const events=['uploaded','guest_uploaded','processed','trashed','restored'];
      const initial=()=>({connected:false,enabled:false,channel:'',teamName:null,events:['uploaded'],revision:0,lastDeliveredAt:null,status:'ready'});
      if(params.has('connected'))records.main={...initial(),connected:true,enabled:true,channel:'#design',teamName:'Design team',revision:1};
      const overview=w=>({available:true,oauthConfigured:!params.has('unconfigured'),canManage:!params.has('readonly'),events,slack:records[w]??initial()});
      const transport={
        async workspaces(){return {items:[{id:'main',name:'Design',role:'owner',permissions:{'integrations.view':true}},{id:'other',name:'Marketing',role:'owner',permissions:{'integrations.view':true}}],canCreate:false};},
        async browse(){window.calls.browse++;return {items:[],hasMore:false};},async neighbors(){return {items:[],hasMore:false};},async status(){return {items:[]};},
        integrations:{async startSlackOAuth(w,input){window.oauth={w,...input};return {authorizationUrl:'https://slack.com/oauth/v2/authorize?client_id=123.456&scope=incoming-webhook&state=fixture-state'};},async overview(w){window.calls.overview++;return overview(w);},async saveSlack(w,input){window.saved={w,...input};records[w]={...input,connected:true,status:'ready',revision:input.revision+1,lastDeliveredAt:null};return overview(w);},async disconnectSlack(w,revision){window.disconnected={w,revision};records[w]={...initial(),revision:revision+1};return overview(w);}}
      };
      mountMediaStudio(document.querySelector('#studio'),{transport,syncUrl:true});
    </script></body></html>`);
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+server.address().port;
  browser=await chromium.launch({headless:true});
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
test('catalog navigation preserves route, hides the context sidebar and filters available integrations',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:800}});const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.goto(origin+'/?fmsView=integrations&fmsWorkspace=main');
    await expect(page.getByRole('heading',{name:'Apps & integrations'})).toBeVisible();
    await expect(page.getByRole('button',{name:'Integrations',exact:true})).toHaveAttribute('aria-current','page');
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
    await page.getByRole('tab',{name:'Developer tools',exact:true}).click();
    await expect(page.getByRole('button',{name:'Explore integration: Slack'})).toHaveCount(0);
    await page.getByRole('tab',{name:'Notifications',exact:true}).click();
    await page.getByRole('button',{name:'Explore integration: Slack'}).click();
    await expect(page.getByRole('heading',{name:'Slack',exact:true})).toBeVisible();
    assert.match(page.url(),/fmsIntegration=slack/);
    await page.reload();await expect(page.getByRole('button',{name:'Connect to Slack',exact:true})).toBeEnabled();
    assert.equal(await page.evaluate(()=>window.calls.browse),0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});
test('Connect to Slack redirects to official authorization without any webhook form',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:800}});
  try{
    await page.route('https://slack.com/oauth/v2/authorize?**',route=>route.fulfill({status:200,contentType:'text/html',body:'<h1>Slack authorization</h1>'}));
    await page.goto(origin+'/?fmsView=integrations&fmsWorkspace=main&fmsIntegration=slack');
    await expect(page.getByLabel('Workspace',{exact:true})).toBeVisible();
    await expect(page.getByLabel('Incoming webhook URL',{exact:true})).toHaveCount(0);
    const request=page.waitForRequest('https://slack.com/oauth/v2/authorize?**');
    await page.getByRole('button',{name:'Connect to Slack',exact:true}).click();
    assert.match((await request).url(),/^https:\/\/slack\.com\/oauth\/v2\/authorize/);
    await expect(page.getByRole('heading',{name:'Slack authorization'})).toBeVisible();
  } finally {await page.close();}
});
test('native HeroUI settings save event selection without credentials and confirm disconnect',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:800}});
  try{
    await page.goto(origin+'/?fmsView=integrations&fmsWorkspace=main&fmsIntegration=slack&connected');
    await page.getByRole('button',{name:'Manage connection',exact:true}).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('dialog').getByRole('textbox')).toHaveCount(0);
    await page.getByRole('dialog').getByText('Files restored',{exact:true}).click();
    await expect(page.getByRole('checkbox',{name:'Files restored',exact:true})).toBeChecked();
    await page.getByRole('button',{name:'Save connection',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    const saved=await page.evaluate(()=>window.saved);assert.equal(saved.w,'main');assert.deepEqual(saved.events,['uploaded','restored']);assert.equal('webhook' in saved,false);
    await page.getByRole('button',{name:'Disconnect Slack',exact:true}).click();
    await expect(page.getByRole('dialog')).toBeVisible();assert.equal(await page.evaluate(()=>window.disconnected),undefined);
    await page.getByRole('button',{name:'Disconnect',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Connect to Slack',exact:true})).toBeVisible();
    assert.equal((await page.evaluate(()=>window.disconnected)).w,'main');
  } finally {await page.close();}
});
test('mobile details have no horizontal overflow and readonly accounts cannot open settings',async()=>{
  const page=await browser.newPage({viewport:{width:390,height:844}});
  try{
    await page.goto(origin+'/?fmsView=integrations&fmsWorkspace=main&fmsIntegration=slack&readonly');
    await expect(page.getByRole('heading',{name:'Slack',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Connect to Slack',exact:true})).toBeDisabled();
    const overflow=await page.locator('.fms-integrations-page').evaluate(node=>node.scrollWidth-node.clientWidth);
    assert.ok(overflow<=1,`Horizontal overflow: ${overflow}`);
    await expect(page.getByText('You can view this connection.',{exact:false})).toBeVisible();
  } finally {await page.close();}
});

test('Slack workspace selection changes the selected scope without opening a manual credentials form',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:800}});
  try{
    await page.goto(origin+'/?fmsView=integrations&fmsWorkspace=main&fmsIntegration=slack');
    await page.getByLabel('Workspace',{exact:true}).click();
    await page.getByRole('option',{name:'Marketing',exact:true}).click();
    await expect(page).toHaveURL(/fmsWorkspace=other/);
    await expect(page.getByRole('button',{name:'Connect to Slack',exact:true})).toBeEnabled();
    await expect(page.getByLabel('Incoming webhook URL',{exact:true})).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.calls.browse),0);
  } finally {await page.close();}
});
