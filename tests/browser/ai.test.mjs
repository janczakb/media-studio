import {localeAssets} from './locale-assets.mjs';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let server,browser,origin;
before(async()=>{
  const assets=new Map(await Promise.all(['studio.js','studio.css','studio-mark.svg','filament.svg'].map(async name=>[name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));
  for(const [name,value]of await localeAssets({raw:true}))assets.set(name,value);
  server=createServer((req,res)=>{
    const path=new URL(req.url,'http://localhost').pathname.slice(1);
    if(assets.has(path)){res.setHeader('Content-Type',path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/javascript');res.end(assets.get(path));return;}
    res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><body style="margin:0"><div id="studio" style="height:100dvh"></div><script type="module">
    import {mountMediaStudio} from '/studio.js';
    window.calls={workspaces:0,browse:0};
    const denied=new URLSearchParams(location.search).has('denied');
    const disabled=new URLSearchParams(location.search).has('disabled');
    const analysis=new URLSearchParams(location.search).has('analysis');
    const transport={async aiInfo(){if(denied)throw Error('Access denied.');return {search:{enabled:analysis},mcp:{installed:true,enabled:!disabled,canUse:!disabled,endpoint:disabled?null:'https://media.example.test/media-studio/mcp',authentication:'bearer',tools:['studio_list_workspaces','studio_list_files','studio_get_file']},features:{imageTags:{implemented:true,enabled:analysis},documentText:{implemented:true,enabled:true},imageOcr:{implemented:false,enabled:false},videoAnalysis:{implemented:false,enabled:false},semanticSearch:{implemented:true,enabled:analysis}}};},async workspaces(){window.calls.workspaces++;return {items:denied?[]:[{id:'main',name:'Main library',role:'owner',permissions:{'members.view':true,'shares.view':true,'notifications.view':true}}],canCreate:false};},management:{async shares(){return {items:[],hasMore:false};},async notifications(){return {items:[],hasMore:false};}},async browse(){window.calls.browse++;return {items:[],hasMore:false};}};
    const reviews=new Map();
    transport.aiLabels={async list(workspace,state,cursor){window.labelLoads=(window.labelLoads??0)+1;const all=Array.from({length:125},(_,i)=>({id:String(i+1),name:'Label '+(i+1),fileName:'Photo '+(i+1),entryId:'entry-'+i,provider:'google_vision',score:.9,state:reviews.get(String(i+1))??'pending',revision:1})).filter(item=>state==='all'||item.state===state);const start=Number(cursor??0);return {items:all.slice(start,start+60),hasMore:start+60<all.length,nextCursor:start+60<all.length?String(start+60):null,canReview:true};},async review(workspace,id,state,revision){window.lastReview={workspace,id,state,revision};reviews.set(id,state);return {id,state,revision:revision+1};}};
    transport.searchIndex=async(workspace,q,mode,cursor)=>{window.lastSearch={workspace,q,mode,cursor};return {items:[{id:'found',name:'Matching file',mediaType:'image',versionId:'version'}],hasMore:false,nextCursor:null,rankingMayChange:true};};
    const params=new URLSearchParams(location.search);
    const layout=params.has('customPanel')?{views:{ai:{panel:{id:'ai-controls',label:'AI controls',render:()=> 'Custom AI controls'},panelWidth:280,header:false}}}:params.has('limitedRail')?{navigation:{items:['library','ai']},views:{files:{panel:false}}}:undefined;
    mountMediaStudio(document.querySelector('#studio'),{transport,syncUrl:true,...(layout?{layout}:{})});
    </script></body></html>`);
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin='http://127.0.0.1:'+server.address().port;
  browser=await chromium.launch({headless:true});
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});
test('AI preserves workspace navigation, native tabs switch and reload restores the page',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:850}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  try{
    await page.goto(origin+'/?fmsView=ai&fmsWorkspace=main&fmsFile=ignored');
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    await expect(page.getByRole('button',{name:'AI',exact:true})).toHaveAttribute('aria-current','page');
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
    await expect(page.getByRole('textbox',{name:'Server address',exact:true})).toHaveValue('https://media.example.test/media-studio/mcp');
    for(const name of ['Members','Shares','Notifications'])await expect(page.locator('.fms-rail').getByRole('button',{name,exact:true})).toBeVisible();
    await page.getByRole('button',{name:'View MCP tools',exact:true}).click();
    await expect(page.getByText('studio_list_files',{exact:true})).toBeVisible();
    await expect(page.getByText('studio_search_files',{exact:true})).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    for(const name of ['Members','Shares','Notifications'])await expect(page.locator('.fms-rail').getByRole('button',{name,exact:true})).toBeVisible();
    assert.deepEqual(await page.evaluate(()=>window.calls),{workspaces:1,browse:0});
    for(const width of [390,768,1200]){
      await page.setViewportSize({width,height:850});
      const overflow=await page.locator('.fms-ai-page').evaluate(el=>el.scrollWidth>el.clientWidth+1);
      assert.equal(overflow,false,'No horizontal overflow at '+width);
    }
    await page.getByRole('tab',{name:'Cursor',exact:true}).click();
    await expect(page.getByText('Add this server to Cursor’s MCP configuration with its URL and an authorization header issued by your application.',{exact:true})).toBeVisible();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
test('unauthorized AI stays hidden and disabled MCP never displays a connection address',async()=>{
  const page=await browser.newPage();
  try{
    await page.goto(origin+'/?denied=1');
    await expect(page.getByRole('heading',{name:'Create a workspace to begin.',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'AI',exact:true})).toHaveCount(0);
    await page.goto(origin+'/?fmsView=ai&disabled=1');
    await expect(page.getByText('Enable MCP in the Laravel configuration to connect an assistant.',{exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Copy address',exact:true})).toHaveCount(0);
    await page.getByRole('tab',{name:'Analysis & search',exact:true}).click();
    await expect(page.getByText('Document text indexing',{exact:true})).toBeVisible();
    await expect(page.getByText('Not available yet',{exact:true})).toHaveCount(0);
  }finally{await page.close();}
});

test('all prompt tabs remain reachable and copying sends the selected prompt',async()=>{
  const context=await browser.newContext({viewport:{width:1200,height:850},permissions:['clipboard-read','clipboard-write']});
  const page=await context.newPage();
  try{
    await page.goto(origin+'/?fmsView=ai');
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    const tabs=page.getByRole('tablist',{name:'Example prompts to try'});
    await expect(tabs.getByRole('tab')).toHaveCount(5);
    const inside=await tabs.evaluate(el=>Array.from(el.querySelectorAll('[role=tab]')).every(tab=>{const r=tab.getBoundingClientRect(),b=el.getBoundingClientRect();return r.width>40&&r.left>=b.left&&r.right<=b.right+1;}));
    assert.equal(inside,true,'All desktop prompts fit in one row');
    await tabs.getByRole('tab',{name:'Compare files',exact:true}).click();
    const selected=await page.locator('.fms-ai-prompt p').first().innerText();
    await page.getByRole('button',{name:'Copy prompt',exact:true}).click();
    assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),selected);
    await expect(page.getByRole('button',{name:'Copied',exact:true})).toBeVisible();
  }finally{await context.close();}
});

test('local demo presents the built AI page', {skip:!process.env.FMS_LIVE_URL},async()=>{
  const page=await browser.newPage({viewport:{width:1391,height:900}});
  try{
    await page.goto(new URL('/?fmsView=ai',process.env.FMS_LIVE_URL).href);
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    await expect(page.getByRole('tab',{name:'Other',exact:true})).toHaveAttribute('aria-selected','true');
    await expect(page.getByRole('tablist',{name:'AI',exact:true})).toHaveAttribute('aria-orientation','vertical');
    await expect(page.getByRole('textbox',{name:'Server address',exact:true})).toBeVisible();
    await page.screenshot({path:fileURLToPath(new URL('../../.internal/ai-mcp-preview.png',import.meta.url))});
  }finally{await page.close();}
});


test('navigation keeps its nodes and permission-derived items when switching AI and Library',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:850}});
  try{
    await page.goto(origin+'/?fmsView=ai&fmsWorkspace=main');
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    const rail=await page.locator('.fms-rail').elementHandle();
    const header=await page.locator('.fms-header').elementHandle();
    const before=await page.locator('.fms-rail-list>li').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().y));
    for(let index=0;index<3;index++){
      await page.locator('.fms-rail').getByRole('button',{name:'Library',exact:true}).click();
      await expect(page.getByRole('heading',{name:'All files',exact:true})).toBeVisible();
      await page.locator('.fms-rail').getByRole('button',{name:'AI',exact:true}).click();
      await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    }
    assert.equal(await page.locator('.fms-rail').evaluate((node,old)=>node===old,rail),true);
    assert.equal(await page.locator('.fms-header').evaluate((node,old)=>node===old,header),true);
    assert.deepEqual(await page.locator('.fms-rail-list>li').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().y)),before);
    assert.equal(await page.evaluate(()=>window.calls.workspaces),1);
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
  }finally{await page.close();}
});

test('layout API renders a dedicated view panel and controls rail and header independently',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:850}});
  try{
    await page.goto(origin+'/?fmsView=ai&fmsWorkspace=main&customPanel');
    await expect(page.getByRole('complementary',{name:'AI controls'})).toHaveText('Custom AI controls');
    assert.equal((await page.locator('.fms-sidebar').boundingBox()).width,280);
    await expect(page.locator('.fms-header')).toHaveCount(0);
    await expect(page.locator('.fms-rail')).toBeVisible();
    await page.goto(origin+'/?fmsView=ai&limitedRail');
    await expect(page.getByRole('heading',{name:'Connect with MCP'})).toBeVisible();
    await expect(page.locator('.fms-rail-list>li:has(button)')).toHaveCount(2);
    await page.locator('.fms-rail').getByRole('button',{name:'Library',exact:true}).click();
    await expect(page.getByRole('heading',{name:'All files',exact:true})).toBeVisible();
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
  }finally{await page.close();}
});


test('AI label review pages through a virtualized window and search uses the selected semantic mode',async()=>{
  const page=await browser.newPage({viewport:{width:1200,height:850}});
  try{
    await page.goto(origin+'/?fmsView=ai&analysis');
    await page.getByRole('tab',{name:'AI labels',exact:true}).click();
    await expect(page.locator('.fms-ai-label-row').filter({hasText:'Label 1'}).first()).toBeVisible();
    await page.locator('.fms-ai-label-row').first().getByRole('button',{name:'Accept',exact:true}).click();
    assert.deepEqual(await page.evaluate(()=>window.lastReview),{workspace:'main',id:'1',state:'accepted',revision:1});
    await page.locator('.fms-ai-label-scroll').evaluate(node=>{node.scrollTop=node.scrollHeight;});
    await expect.poll(()=>page.evaluate(()=>window.labelLoads)).toBeGreaterThan(1);
    assert.ok(await page.locator('.fms-ai-label-row').count()<25);
    await page.getByRole('tab',{name:'Search content',exact:true}).click();
    await page.getByRole('textbox',{name:'Search query',exact:true}).fill('biały kot');
    await page.getByRole('button',{name:/Search mode/}).click();
    await page.getByRole('option',{name:'Meaning',exact:true}).click();
    await page.getByRole('button',{name:'Search',exact:true}).click();
    await expect(page.getByRole('button',{name:'Matching file',exact:true})).toBeVisible();
    assert.deepEqual(await page.evaluate(()=>window.lastSearch),{workspace:'main',q:'biały kot',mode:'semantic',cursor:null});
  }finally{await page.close();}
});
