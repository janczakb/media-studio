import {localeAssets} from './locale-assets.mjs';
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {mkdir,mkdtemp,readdir,readFile,rm,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium, expect} from '@playwright/test';

let browser, server, origin;
before(async () => {
  const files = new Map(await Promise.all([
    ['workspace-emoji.js','text/javascript'],['studio.js','text/javascript'],['video-player.js','text/javascript'],['studio.css','text/css'],['filament.svg','image/svg+xml'],['studio-mark.svg','image/svg+xml'],
  ].map(async ([name, type]) => [`/${name}`, {type, body:await readFile(new URL(`../../packages/react/dist/${name}`, import.meta.url))}])));
  for(const [name,value]of await localeAssets({raw:false}))files.set(name,value);
  server = createServer((request, response) => {
    const file = files.get(new URL(request.url, 'http://localhost').pathname);
    response.writeHead(200, {'Content-Type':file?.type ?? 'text/html'});
    response.end(file?.body ?? '<!doctype html><html><body style="margin:0"><div id="studio" style="height:100vh"></div></body></html>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch();
});
after(async () => {await browser?.close(); await new Promise(resolve => server?.close(resolve));});

async function preview(width = 1440, options = {}, fixtures = {}) {
  const page = await browser.newPage({viewport:{width,height:900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.evaluate(async ({options,fixtures}) => {
    const {mountMediaStudio} = await import('/studio.js');
    if(fixtures.dictationUnsupported){Object.defineProperty(window,'SpeechRecognition',{configurable:true,value:undefined});Object.defineProperty(window,'webkitSpeechRecognition',{configurable:true,value:undefined});}
    if(fixtures.dictation){
      window.dictationTracksStopped=0;window.dictationStarts=0;
      Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:()=>new Promise((resolve,reject)=>{window.resolveMicrophone=()=>resolve({getTracks:()=>[{stop(){window.dictationTracksStopped++;}}]});window.rejectMicrophone=()=>reject(new DOMException('Permission denied','NotAllowedError'));})}});
      window.SpeechRecognition=class {
        constructor(){window.dictationRecognition=this;}
        start(){window.dictationStarts++;}
        stop(){this.onend?.();}
        abort(){window.dictationAborted=(window.dictationAborted??0)+1;}
      };
    }

    if(fixtures.noRandomUUID)Object.defineProperty(crypto,'randomUUID',{value:undefined});
    const workspaces = [{id:'one',name:'Brand assets',role:'owner'}, {id:'two',name:'Campaign',role:'viewer'}];
    if(fixtures.librarySearch){workspaces[0].permissions={'members.view':true};}
    if(fixtures.workspaceIdentity){for(const w of workspaces)w.workspaceType='team';}
    if(fixtures.workspaceSettings){for(const w of workspaces){w.role='owner';w.permissions={'workspaces.manage':true};}}
    const requests = [], neighborRequests = [], transferRequests = [], workspaceRequests = [], contentRequests = [], statusRequests = [], starRequests = [], visits = [], bulkRequests = [];
    const items = Array.from({length:fixtures.files??0},(_,index)=>{
      const id=String(index).padStart(3,'0');
      return {id,name:`Photo ${id}.svg`,kind:'file',mediaType:'image',mimeType:'image/svg+xml',size:1024,versionId:`v${id}`,updatedAt:'2026-11-06T00:00:00Z',revision:1,trashedAt:null,width:320,height:180,thumbnailUrl:fixtures.previewVariants&&index===2?null:'/filament.svg?thumb='+id,readOnly:index===2,...(fixtures.previewVariants?{previewUrl:index===0?'/rendition/'+id:null,thumbnailState:'ready',previewState:index===1&&fixtures.pendingPreview?'queued':'ready'}:{})};
    });
    if(fixtures.intrinsicImage&&items[0])Object.assign(items[0],{width:4000,height:2000});
    const folders = fixtures.organize ? [{id:'folder',name:'Archive',kind:'folder',mediaType:null,mimeType:null,size:null,versionId:null,updatedAt:'2026-11-06T00:00:00Z',revision:1,trashedAt:null,starred:true,parent:null}] : [];
    if(fixtures.video&&items[0])Object.assign(items[0],{mediaType:'video',mimeType:'video/webm',width:64,height:36,thumbnailUrl:null});
    if(fixtures.audio&&items[0])Object.assign(items[0],{name:'Recording.wav',mediaType:'audio',mimeType:'audio/wav',width:null,height:null,thumbnailUrl:null});
    const library = [...folders,...items];
    const viewItems = query=>{
      if(!fixtures.organize)return items;
      if(query.view==='folders')return folders.filter(item=>item.parent===query.parent&&!item.trashedAt);
      if(query.view==='trash')return library.filter(item=>item.trashedAt);
      if(query.view==='starred')return library.filter(item=>item.starred&&!item.trashedAt);
      if(query.view==='recent')return [...new Set(visits.map(visit=>visit.id).reverse())].map(id=>library.find(item=>item.id===id)).filter(item=>item&&!item.trashedAt);
      return library.filter(item=>(item.parent??null)===query.parent&&!item.trashedAt);
    };
    let workspaceOperation=null;let settingsMembers=[{id:'owner',name:'Workspace owner',email:'owner@example.test',role:'owner',canRemove:false},{id:'ada',name:'Ada Nowak',email:'ada@example.test',role:'editor',canRemove:true}];if(fixtures.workspaceAccess){for(const w of workspaces)w.permissions={...w.permissions,'members.view':true,'members.manage':true,'members.invite':true};}
    if(fixtures.memberCount)settingsMembers=Array.from({length:fixtures.memberCount},(_,index)=>({id:'member-'+index,name:'Member '+index,email:'member'+index+'@example.test',role:'viewer',canRemove:true}));
    const transport = {
      ...(fixtures.workspaceAccess?{
        async members(workspace,cursor){window.settingsMemberLoads=(window.settingsMemberLoads??0)+1;return {items:settingsMembers.map(u=>({...u})),nextCursor:null,hasMore:false};},
        async updateMember(workspace,id,role){window.settingsRoleChange={workspace,id,role};settingsMembers=settingsMembers.map(u=>u.id===id?{...u,role}:u);},
        async removeMember(workspace,id){window.settingsRemoved=id;settingsMembers=settingsMembers.filter(u=>u.id!==id);},
        async invitations(){return {items:[{id:'pending',email:'invited@example.test',role:'viewer',expires_at:'2030-01-01T00:00:00Z',accepted_at:null,revoked_at:null}],nextCursor:null};},
        async memberCandidates(){return {items:[{id:'anna',type:'user',name:'Anna Kowalska',email:'anna@example.test'}]};},
        async assignMember(workspace,value){window.settingsAdded=value;settingsMembers.push({...value,name:'Anna Kowalska',email:'anna@example.test',canRemove:true});},
      }:{}),
      ...(fixtures.workspaceInvites?{async inviteMember(_workspace,value){(window.invitationAttempts??=[]).push(value);if(value.email==='second@example.com'&&!window.failedInvitationOnce){window.failedInvitationOnce=true;throw new Error('Temporary mail failure');}}}:{}),
      ...(fixtures.workspaceSettings?{
        async updateWorkspace(id,name,_signal,identity){window.savedWorkspaceIdentity=identity;return {...workspaces.find(w=>w.id===id),name,...identity};},
        async retireWorkspace(id,input){window.workspaceRetirementInput={id,...input};workspaceOperation={id:'retirement-one',workspace:id,mode:input.mode,target:input.target??null,state:'queued',phase:'entries',processed:0,error:null};return workspaceOperation;},
        async workspaceOperation(){return workspaceOperation;},
      }:{}),
      ...(fixtures.assistant?{
        async aiInfo(){return {mcp:{installed:false,enabled:false,canUse:false,endpoint:null,authentication:'bearer',tools:[]},features:{},assistant:{enabled:fixtures.assistant!=='disabled',models:[{id:'fast',label:fixtures.longModel?'An extremely long configured model name for a narrow panel':'Fast model',usageWeight:1}]}};},
        assistant:{
          async undo(_w,id){(window.assistantUndone??=[]).push(id);return {ok:true};},
          async upload(_w,file){return {id:'attachment-one',name:file.name,size:file.size,mimeType:file.type};},
          async rename(_w,id,title,revision){window.fixtureChats=[{id,title,revision:revision+1}];return window.fixtureChats[0];},
          async remove(_w,id,revision){const item=window.fixtureChats?.find(row=>row.id===id)??{id,title:'Saved conversation'};window.fixtureChats=[];return {...item,revision:revision+1};},
          async restore(_w,id,revision){window.fixtureChats=[{id,title:'Renamed conversation',revision:revision+1}];return window.fixtureChats[0];},
          async profile(){return window.assistantProfile??{revision:0,name:'Sage',character:null};},
          async saveProfile(_w,profile){window.assistantProfile={...profile,revision:profile.revision+1};return window.assistantProfile;},
          async list(_w,cursor){(window.assistantHistoryRequests??=[]).push(cursor);if(fixtures.assistantHistoryCount){const start=Number(cursor??0),end=Math.min(start+30,fixtures.assistantHistoryCount);return {items:Array.from({length:end-start},(_,i)=>({id:start+i===0?'saved-chat':'chat-'+(start+i),title:start+i===0?'Saved conversation':'Conversation '+(start+i),revision:1})),nextCursor:end<fixtures.assistantHistoryCount?String(end):null};}return {items:window.fixtureChats??[{id:'saved-chat',title:'Saved conversation',revision:1}],nextCursor:null};},
          async create(){window.chatThread={id:'new-chat',title:'New chat',messages:[],run:null};return window.chatThread;},
          async show(_w,id,_signal,page){(window.assistantShowRequests??=[]).push({id,page:page??null});if(id==='saved-chat'&&fixtures.assistantMessageCount){const count=fixtures.assistantMessageCount,edge=Number((page?.before??page?.after??'').replace('m','')),start=page?.before?Math.max(0,edge-30):page?.after?edge+1:Math.max(0,count-30),end=Math.min(count,start+30);return {id,title:'Saved conversation',messages:Array.from({length:end-start},(_,i)=>({id:'m'+String(start+i).padStart(6,'0'),role:(start+i)%2?'assistant':'user',content:'Message '+(start+i)+' '+('paragraph words '.repeat((start+i)%3*4)),run_id:'saved-run'})),nextCursor:start>0?'m'+String(start).padStart(6,'0'):null,newerCursor:end<count?'m'+String(end-1).padStart(6,'0'):null,run:null};}if(id==='saved-chat')return {id,title:'Saved conversation',messages:[{id:'saved-message',role:'assistant',content:'Earlier answer',run_id:'saved-run'}],run:null};return structuredClone(window.chatThread);},
          async send(_w,_id,input){window.chatInput=input;window.chatThread.messages.push({id:'user-message',role:'user',content:input.text,run_id:'run-one'});window.chatThread.run={id:'run-one',state:'running',model_key:'fast',usage:null,error:null};return {id:'run-one'};},
          async cancel(){window.assistantCancelCount=(window.assistantCancelCount??0)+1;window.chatThread.run.state='cancelled';return {ok:true};},
          async events(_w,_id,_run,after,onEvent,signal){
            window.assistantEventSubscriptions=(window.assistantEventSubscriptions??0)+1;
            if(signal.aborted)return;
            if(fixtures.assistantSlow){await new Promise(resolve=>signal.addEventListener('abort',resolve,{once:true}));return;}
            if(fixtures.assistantActions){const actions=[{ok:true,action:'rename',actionId:'rename-one',undoable:true,entry:{id:'000',name:'Renamed.svg',kind:'file',revision:2}},{ok:true,action:'open',actionId:'open-one',undoable:false,command:'open',entry:{id:'000',name:'Renamed.svg',kind:'file',revision:2}}];for(const [i,data] of actions.entries())onEvent({id:10+i,type:'action',data});window.chatThread.run.actions=actions;}
            for(const [i,text] of ['Streamed ','response'].entries()){await new Promise(resolve=>setTimeout(resolve,120));if(signal.aborted)return;onEvent({id:i+1,type:'delta',data:{text}});}
            window.chatThread.messages.push({id:'assistant-message',role:'assistant',content:'Streamed response',run_id:'run-one'});window.chatThread.run.state='completed';window.chatThread.run.usage={input_tokens:10,output_tokens:5};onEvent({id:3,type:'done',data:{state:'completed'}});
          },
        },
      }:{}),
      ...(fixtures.inboxToasts?{management:{
        async notifications(workspace){
          (window.inboxRequests??=[]).push(workspace);
          window.inboxItems??=[{id:'existing',event:'mentioned',name:'Previous mention',readAt:null}];
          return {items:[...window.inboxItems],nextCursor:null,previousCursor:null,hasMorePages:false};
        },
      }}:{}),
      ...(fixtures.comments?{comments:{
        async list(_w,_e,filter,cursor){
          if(fixtures.delayedDrawingComments)await new Promise(resolve=>window.releaseDrawingComments=resolve);
          if(!window.commentItems)window.commentItems=Array.from({length:fixtures.commentCount??0},(_,i)=>({id:'comment-'+i,body:'Comment '+i+' '+('variable content '.repeat(i%4)),mentions:[],revision:1,author:{name:'Ada',avatarUrl:null},createdAt:new Date().toISOString(),updatedAt:new Date().toISOString(),resolved:false,canEdit:true,canResolve:true}));
          (window.commentRequests??=[]).push(cursor);
          const all=window.commentItems.filter(c=>filter==='all'||c.resolved===(filter==='resolved'));const offset=Number(cursor??0),next=offset+30<all.length?String(offset+30):null;
          return {items:all.slice(offset,offset+30),nextCursor:next,hasMore:next!==null,canCreate:true};
        },
        async create(_w,_e,input){const c={id:'comment-1',anchor:input.anchor,document:input.document,body:input.body,mentions:input.mentions,revision:1,author:{name:'Ada',avatarUrl:null},createdAt:new Date().toISOString(),updatedAt:new Date().toISOString(),resolved:false,canEdit:true,canResolve:true};window.commentItems=[c];return c;},
        async update(_w,_e,id,input){if(input.delete){window.commentItems=[];return {deleted:true};}const c=window.commentItems.find(c=>c.id===id);Object.assign(c,input,{revision:c.revision+1});return c;},
        async mentions(){return {items:[{id:'user-uuid-1',principalType:'user',memberId:'member-1',name:'Alex',avatarUrl:null}]};},
      }}:{}),
      supportsBrowseControls:fixtures.controls===true,
      ...(fixtures.librarySearch?{
       async members(workspace,cursor,signal,search){
        (window.uploaderRequests??=[]).push({workspace,cursor,search});
        signal.throwIfAborted();
        return {items:search==='Ada'?[{id:'999',name:'Ada Nowak'}]:[{id:'1',name:'Owner'}],nextCursor:null,hasMore:false};
       },
       async librarySearch(workspace,query,cursor,signal){
        (window.librarySearchRequests??=[]).push({workspace,query,cursor});
        if(fixtures.slowSearch&&query.limit!==3)await new Promise(resolve=>setTimeout(resolve,220));
        signal.throwIfAborted();
        const matches=library.filter(entry=>(!query.kind||entry.kind===query.kind)&&(!query.text||entry.name.toLowerCase().startsWith(query.text.toLowerCase())));
        const start=Number(cursor??0),end=start+(query.limit??24);
        return {items:matches.slice(start,end),nextCursor:end<matches.length?String(end):null,hasMore:end<matches.length};
      }}:{}),
      async workspaces() {workspaceRequests.push('list');return {items:fixtures.singleWorkspace?[workspaces[0]]:[...workspaces],canCreate:!fixtures.singleWorkspace,workspacesEnabled:!fixtures.singleWorkspace,workspaceIdentity:!!fixtures.workspaceIdentity,...(fixtures.brandingMetadata?{appearance:{primaryColor:'#17aa55'},viewer:{name:'Server user',email:'server@example.test'}}:{})};},
      async workspace(id) {workspaceRequests.push(id);return workspaces.find(workspace=>workspace.id===id);},
      ...(fixtures.workspacePeople?{
        async workspaceCreationCandidates(workspace,search,signal){window.peopleSearches??=[];window.peopleSearches.push(search);return {items:Array.from({length:20},(_,i)=>({id:String(i+1),type:'user',name:'Teammate '+(i+1),email:'team'+(i+1)+'@example.test',role:'viewer',canRemove:false}))};},
        async assignMember(workspace,value){window.peopleAssignments??=[];window.peopleAssignments.push({workspace,...value});},
      }:{}),
      async createWorkspace(name,_signal,identity) {window.createdWorkspaceInput={name,...identity};window.workspaceCreates=(window.workspaceCreates??0)+1;if(fixtures.deferWorkspaceCreate)await new Promise(resolve=>window.resolveWorkspaceCreate=resolve);const item = {id:crypto.randomUUID(),name,role:'owner',emoji:identity?.emoji,workspaceType:identity?.kind}; workspaces.push(item); return item;},
      async browse(workspace,query,_cursor,signal) {requests.push({workspace,query});if(fixtures.browseDelay)await new Promise((resolve,reject)=>{const timer=setTimeout(resolve,fixtures.browseDelay);signal?.addEventListener('abort',()=>{clearTimeout(timer);reject(new DOMException('Aborted','AbortError'));},{once:true});}); return {items:viewItems(query).slice(0,fixtures.organize?120:60),nextCursor:null,previousCursor:null,hasMore:false,canWrite:workspace==='one'};},
      async neighbors(workspace,id,query,direction,_signal,cursor) {
        neighborRequests.push({workspace,id,query,direction,cursor});
        if(fixtures.hiddenNeighborWindow&&direction==='next'&&id==='000'&&!cursor)
          return {items:[],hasMore:true,nextCursor:'resume-after-hidden-window',previousCursor:null};
        const visible=viewItems(query).filter(item=>item.kind==='file');
        const index=visible.findIndex(entry=>entry.id===id);
        const matches=direction==='previous'?visible.slice(0,index):visible.slice(index+1);
        return {items:direction==='previous'?matches.slice(-60):matches.slice(0,60),hasMore:matches.length>60};
      },
      async details(_workspace,id) {if(fixtures.detailsDelay)await new Promise(resolve=>setTimeout(resolve,fixtures.detailsDelay));const entry=library.find(item=>item.id===id);if(!entry)throw new Error('Missing entry');return {...entry,parentId:entry.parent??null,metadata:{alt:'A test photograph',drawing:entry.drawing},createdAt:entry.updatedAt};},
      ...(fixtures.drawing ? {async update(_workspace, id, payload) {
        const entry = library.find(item => item.id === id);
        if (!entry || payload.revision !== entry.revision) throw new Error('Stale drawing');
        if('drawing' in payload)entry.drawing = payload.drawing;
        if(payload.name)entry.name=payload.name;
        window.lastEntryUpdate=payload;
        entry.revision++;
        return {...entry};
      }} : {}),
      async status(workspace,ids) {statusRequests.push({workspace,ids});return {items:items.filter(item=>ids.includes(item.id)).map(item=>({...item,previewState:'ready',previewUrl:'/rendition/'+item.id}))};},
      ...(fixtures.responsiveControls?{async browseChoices(_workspace,kind){return {items:kind==='tags'?[{id:'tag',label:'Campaign'}]:kind==='extensions'?[{id:'svg',label:'svg'}]:[{id:'en',label:'English'}],nextCursor:null};},archiveUrl(){return '/archive';},management:{async createShare(){throw new Error('Not used');}}}:{}),
      async createFolder() {throw new Error('Not used by browser fixtures');},
      async upload() {throw new Error('Not used by browser fixtures');},
      contentUrl(_workspace,id,download=false) {contentRequests.push({id,download});return '/original/'+id+(download?'?download=1':'');},
      ...(fixtures.organize?{
        async star(workspace,id,starred){starRequests.push({workspace,id,starred});library.find(item=>item.id===id).starred=starred;return {starred};},
        async visit(workspace,id){visits.push({workspace,id});},
        async bulk(workspace,request){
          bulkRequests.push({workspace,...request});
          if(window.failBulk){window.failBulk=false;throw new Error('The selection changed. Try again.');}
          return {items:request.items.map(({id,revision})=>{
            const item=library.find(value=>value.id===id);
            if(item.revision!==revision)throw new Error('Stale revision.');
            item.revision++;
            if(request.operation==='move')item.parent=request.parent;
            if(request.operation==='trash')item.trashedAt='2026-11-07T00:00:00Z';
            if(request.operation==='restore')item.trashedAt=null;
            return item;
          })};
        },
      }:{}),
      ...(fixtures.transfers?{transfers:{
        async policy(){return {enabled:true,chunkBytes:1000,maxBytes:1000000,maxParts:1000,maxConcurrent:2,ordinaryMaxBytes:1000000};},
        async resolveFolders(workspace,parent,paths){transferRequests.push({workspace,parent,paths});return {items:paths.map(path=>({path,id:path}))};},
        async ordinary(workspace,parent,file,key,signal,progress){
          transferRequests.push({workspace,parent,name:file.name});
          progress(Math.floor(file.size/2));
          await new Promise((resolve,reject)=>{
            const timer=setTimeout(resolve,fixtures.transferDelay??30);
            signal.addEventListener('abort',()=>{clearTimeout(timer);reject(signal.reason);},{once:true});
          });
          if(file.name==='broken.txt')throw new Error('Fixture transfer failed');
          return {id:key,name:file.name,kind:'file',mediaType:'document',mimeType:file.type,size:file.size,versionId:key,updatedAt:'2026-11-06T00:00:00Z',revision:1,trashedAt:null};
        },
      }}:{}),
    };
    window.testRequests = requests;
    window.neighborRequests = neighborRequests;
    window.transferRequests = transferRequests;
    window.workspaceRequests = workspaceRequests;
    window.contentRequests = contentRequests;
    window.statusRequests = statusRequests;
    window.starRequests = starRequests;
    window.visits = visits;
    window.bulkRequests = bulkRequests;
    if(fixtures.legacyWorkspace)delete transport.workspace;
    window.fixtureOptions={transport,onSelect:reference=>{window.selection=reference;},...options};
    if(fixtures.imageSDK)window.fixtureOptions.onPreviewReady=handle=>{window.previewController=handle;};
    if(fixtures.imageSDK)window.fixtureOptions.imagePreview={...(options.imagePreview??{}),onReady(handle){window.imageViewport=handle;if(handle)window.lastImageViewport=handle;},onViewport(state){window.imageViewportState=state;}};
    window.remountStudio=()=>{window.instance.destroy();window.instance=mountMediaStudio(document.querySelector('#studio'),window.fixtureOptions);};
    window.instance = mountMediaStudio(document.querySelector('#studio'), window.fixtureOptions);
  }, {options,fixtures});
  await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
  return {page,errors};
}

test('old navigation proportions, bundled logo, mouse/keyboard actions and isolated HeroUI tooltip', async () => {
  const {page,errors} = await preview();
  try {
    assert.equal((await page.locator('.fms-rail').boundingBox()).width,48);
    assert.equal((await page.locator('.fms-sidebar').boundingBox()).width,248);
    const railGeometry=await page.locator('.fms-rail').evaluate(rail=>({
      icons:[...rail.querySelectorAll('button>svg')].map(icon=>({width:icon.getBoundingClientRect().width,height:icon.getBoundingClientRect().height,stroke:icon.getAttribute('stroke-width')})),
      controls:[...rail.querySelectorAll('button')].map(button=>({width:button.getBoundingClientRect().width,height:button.getBoundingClientRect().height,center:button.getBoundingClientRect().x+button.getBoundingClientRect().width/2})),
    }));
    assert.ok(railGeometry.icons.length>=2);
    assert.ok(railGeometry.icons.every(icon=>icon.width===24&&icon.height===24&&icon.stroke==='1.5'));
    assert.ok(railGeometry.controls.every(button=>button.width===36&&button.height===36&&button.center===24));

    assert.equal(await page.locator('.fms-rail-logo').evaluate(image => image.complete && image.naturalWidth > 0),true);
    await page.getByRole('button',{name:'Photos',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Photos');
    await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'All files',exact:true}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
    await page.mouse.move(600,400);
    await page.getByRole('button',{name:'FLEX Media Studio',exact:true}).hover();
    await expect(page.getByRole('tooltip')).toBeVisible();
    await expect(page.getByRole('tooltip')).toHaveText('FLEX Media Studio');
    assert.equal(await page.getByRole('tooltip').evaluate(element => element.getRootNode() instanceof ShadowRoot),true);
    await page.mouse.move(600,400);
    await expect(page.getByRole('button',{name:'Workspaces',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Account menu',exact:true}).click();
    await page.getByRole('menuitem',{name:'Campaign',exact:true}).click();
    await expect(page.locator('.fms-sidebar-heading')).toHaveText('Library');
    await expect(page.getByRole('button',{name:'Upload files',exact:true})).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.testRequests.at(-1).workspace),'two');
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('mobile disclosure keeps the rail, closes on selection/Escape/outside click and avoids overflow', async () => {
  const {page,errors} = await preview(390);
  try {
    assert.equal((await page.locator('.fms-rail').boundingBox()).width,48);
    await expect(page.locator('.fms-sidebar')).toBeHidden();
    const trigger=page.getByRole('button',{name:'Open navigation',exact:true});
    await expect(trigger.locator('svg')).toHaveCSS('width','28px');
    await expect(trigger.locator('svg path')).toHaveCount(2);
    await trigger.click();
    await expect(page.locator('.fms-sidebar')).toBeVisible();
    await page.getByRole('button',{name:'Photos',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Photos');
    await expect(page.locator('.fms-sidebar')).toBeHidden();
    await trigger.click();
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    await expect(page.locator('.fms-sidebar')).toBeHidden();
    await trigger.click();
    await page.getByRole('button',{name:'Close navigation',exact:true}).click({position:{x:325,y:450}});
    await expect(page.locator('.fms-sidebar')).toBeHidden();
    assert.equal(await page.locator('.fms-shell').evaluate(element => element.scrollWidth > element.clientWidth),false);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('desktop navigation keeps the sidebar visible and omits the hamburger with a docked chat',async()=>{
  const {page,errors}=await preview(1100,{}, {assistant:true});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await expect(page.getByRole('complementary',{name:'AI assistant',exact:true})).toBeVisible();
    await expect(page.locator('.fms-sidebar')).toBeVisible();
    await expect(page.locator('.fms-navigation-trigger')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('HeroUI dialog accepts typing/submission and dark/custom branding is instance configured', async () => {
  const {page,errors} = await preview(1440,{theme:'dark',branding:{logoUrl:'/filament.svg?custom',logoLabel:'My studio'}});
  try {
    await expect(page.locator('.fms-root')).toHaveClass(/dark/);
    assert.equal(await page.locator('.fms-rail-logo').getAttribute('src'),'/filament.svg?custom');
    await expect(page.getByRole('button',{name:'My studio',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Account menu',exact:true}).click();
    await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Create workspace',exact:true})).toBeVisible();
    await page.getByRole('textbox',{name:'Workspace name',exact:true}).fill('Product launch');
    await page.getByRole('button',{name:'Continue',exact:true}).click();
    await page.getByRole('button',{name:'Continue',exact:true}).click();
    await page.getByRole('button',{name:'Create workspace',exact:true}).click();
    await page.getByRole('button',{name:'Open workspace',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Create workspace',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Account menu',exact:true}).click();
    await expect(page.getByRole('menuitem',{name:'Product launch',exact:true}).getByLabel('Selected',{exact:true})).toBeVisible();
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('preview follows an opaque continuation past a hidden source window', async () => {
  const {page,errors} = await preview(1440,{}, {files:4,hiddenNeighborWindow:true});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.neighborRequests.some(request=>request.cursor==='resume-after-hidden-window'))).toBe(true);
    await page.getByRole('button',{name:'Next file',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Photo 001.svg'})).toBeVisible();
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('thumbnail opens immediately, neighbors prefetch in scope, and the read-only inspector omits edits', async () => {
  const {page,errors} = await preview(1440,{}, {files:175});
  let releaseOriginal;
  const originalGate = new Promise(resolve=>{releaseOriginal=resolve;});
  await page.route('**/original/*',async route=>{
    await originalGate;
    await route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="green"/></svg>'}).catch(()=>{});
  });
  try {
    const card = page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true});
    await expect.poll(()=>card.locator('img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
    await card.click();
    const dialog = page.getByRole('dialog',{name:'Photo 002.svg'});
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-placeholder')).toBeVisible();
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-original')).toHaveCSS('opacity','0');
    await expect(dialog.locator('.fms-preview-spinner')).toHaveCount(0);
    await expect.poll(()=>dialog.locator('.fms-preview-visual').evaluate(element=>{
      const matrix=new DOMMatrixReadOnly(getComputedStyle(element).transform);
      return Math.abs(matrix.a-1)<.001 && Math.abs(matrix.d-1)<.001 && Math.abs(matrix.e)<.1 && Math.abs(matrix.f)<.1;
    })).toBe(true);
    await expect(dialog.getByText('A test photograph')).toBeVisible();
    await expect(dialog.getByRole('textbox')).toHaveCount(0);
    await expect(dialog.getByRole('toolbar',{name:'File actions'}).getByRole('button',{name:'Move to trash',exact:true})).toBeDisabled();
    await expect.poll(()=>page.evaluate(()=>window.neighborRequests.filter(req=>req.id==='002').map(req=>req.direction).sort())).toEqual(['next','previous']);
    assert.ok(await page.evaluate(()=>window.neighborRequests.every(req=>req.workspace==='one'&&req.query.view==='files'&&req.query.parent===null&&req.query.search==='')));
    releaseOriginal();
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-original')).toHaveCSS('opacity','1');
    await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('dialog',{name:'Photo 003.svg'})).toBeVisible();
    await expect(page.getByRole('button',{name:'Save changes',exact:true})).toBeVisible();
    await page.keyboard.press('ArrowLeft');
    await expect(dialog).toBeVisible();
    const previous = page.getByRole('button',{name:'Previous file',exact:true});
    await previous.click();
    await previous.click();
    await expect(page.getByRole('dialog',{name:'Photo 000.svg'})).toBeVisible();
    await expect(previous).toBeDisabled();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expect(dialog).toBeVisible();
    assert.equal(await page.evaluate(()=>window.neighborRequests.length),2);
    await dialog.getByRole('button',{name:'Close',exact:true}).click();
    await expect(dialog).toHaveCount(0);
    await expect(card).toBeVisible();
    assert.deepEqual(errors,[]);
  } finally {releaseOriginal();await page.close();}
});

test('preview swipes horizontally and fades/scales the complete surface during downward dismissal', async () => {
  const {page,errors}=await preview(1440,{}, {files:12});
  try {
    await page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Photo 002.svg'})).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.neighborRequests.length)).toBe(2);
    assert.equal(await page.locator('.fms-preview-pages').evaluate(el=>Math.round(el.getBoundingClientRect().width)),await page.locator('.fms-preview-stage').evaluate(el=>Math.round(el.getBoundingClientRect().width)));
    await expect(page.locator('.fms-preview-header')).toHaveCSS('border-bottom-width','0px');
    await expect(page.locator('.fms-preview-surface')).toHaveCSS('color-scheme','light');
    await expect(page.locator('[data-preview-active="true"] .fms-preview-visual')).toHaveCSS('border-radius','0px');
    assert.equal(await page.locator('.fms-preview-surface').evaluate(el=>getComputedStyle(el,'::after').backgroundColor),'rgb(255, 255, 255)');
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toHaveCSS('background-color',/^(rgb\(255, 255, 255\)|oklch\(1 0 0\))$/);
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toHaveCSS('border-left-width','1px');
    const panel=await page.locator('.fms-preview-layout>.fms-inspector').boundingBox();
    const stage=await page.locator('.fms-preview-stage').boundingBox();
    assert.ok(panel.x>=stage.x+stage.width,'information panel stays on the right');
    const header=await page.locator('.fms-preview-header').boundingBox();
    const surface=await page.locator('.fms-preview-surface').boundingBox();
    assert.equal(Math.round(header.x+header.width),Math.round(panel.x));
    assert.equal(Math.round(panel.y),Math.round(surface.y));
    assert.equal(Math.round(panel.height),Math.round(surface.height));
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toHaveCSS('margin','0px');
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toHaveCSS('border-radius','0px');
    const next=page.getByRole('button',{name:'Next file',exact:true});
    await expect(next).toHaveCSS('border-radius','9999px');
    await expect(next.locator('svg')).toHaveCSS('width','20px');
    await expect(next.locator('svg')).toHaveCSS('stroke-width','1.75px');
    const drag=page.locator('[data-preview-active="true"] .fms-preview-drag');
    let box=await drag.boundingBox();
    let x=box.x+box.width/2,y=box.y+box.height/2;
    await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x-160,y,{steps:14});
    await expect(page.locator('.fms-preview-neighbor img').first()).toBeVisible();
    await page.mouse.up();
    await expect(page.getByRole('dialog',{name:'Photo 003.svg'})).toBeVisible();
    await expect.poll(()=>page.locator('[data-preview-active="true"]').evaluate(el=>Math.abs(new DOMMatrixReadOnly(getComputedStyle(el).transform).e))).toBeLessThan(.1);
    box=await drag.boundingBox();x=box.x+box.width/2;y=box.y+box.height/2;
    await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x,y+180,{steps:14});
    await expect.poll(()=>page.locator('.fms-preview-header').evaluate(el=>Number(getComputedStyle(el).opacity))).toBeLessThan(.9);
    const frost=await page.locator('.fms-preview-surface').evaluate(el=>({blur:getComputedStyle(el,'::before').backdropFilter,frost:Number(getComputedStyle(el,'::before').opacity),solid:Number(getComputedStyle(el,'::after').opacity)}));
    assert.match(frost.blur,/blur\(24px\)/);
    assert.ok(frost.frost>frost.solid);
    await expect(page.locator('[data-preview-active="true"] .fms-preview-visual')).toHaveCSS('filter','none');
    await page.mouse.up();
    await expect(page.locator('.fms-preview-return')).toHaveCount(1);
    await expect(page.locator('.fms-preview-return')).toHaveCSS('opacity','1');
    await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
    await expect(page.locator('.fms-preview-return')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('workspace-scoped picker keeps its inline preview and selection within the assigned workspace', async () => {
  const {page,errors} = await preview(390,{workspaceId:'two',mode:'picker'}, {files:2});
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="green"/></svg>'}));
  try {
    assert.deepEqual(await page.evaluate(()=>window.workspaceRequests),['two']);
    assert.equal(await page.evaluate(()=>window.testRequests.at(-1).workspace),'two');
    await expect(page.getByRole('button',{name:'Upload files',exact:true})).toHaveCount(0);
    const card=page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true});
    await expect.poll(()=>card.locator('img').evaluate(img=>img.complete&&img.naturalWidth>0)).toBe(true);
    await card.click();
    const inline=page.getByRole('region',{name:'Photo 000.svg',exact:true});
    await expect(inline).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(inline.locator('.fms-preview-original')).toBeVisible();
    await expect(inline.locator('.fms-inspector')).toHaveCount(0);
    await expect(inline.getByRole('button',{name:'Zoom in',exact:true})).toBeVisible();
    assert.equal(await inline.evaluate(visual=>visual.getAnimations().length),0);
    const [nameTop,extensionTop]=await inline.locator('.fms-preview-file-title').evaluate(el=>[...el.querySelectorAll('span')].map(part=>part.getBoundingClientRect().top));
    assert.ok(Math.abs(nameTop-extensionTop)<1);
    const more=inline.getByRole('button',{name:'More actions',exact:true});
    const menu=page.getByRole('menu',{name:'More actions',exact:true});
    await more.click();
    await expect(menu).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(inline).toBeVisible();
    await more.click();
    await expect(menu).toBeVisible();
    await inline.getByRole('heading').click();
    await expect(menu).toHaveCount(0);
    await more.click();
    await expect(menu).toBeVisible();
    await more.click();
    await expect(menu).toHaveCount(0);
    await page.getByRole('button',{name:'Use this file',exact:true}).click();
    await expect(inline).toHaveCount(0);
    assert.deepEqual(await page.evaluate(()=>window.selection),{workspaceId:'two',assetId:'000',versionId:'v000'});
    assert.ok(await page.evaluate(()=>window.testRequests.every(request=>request.workspace==='two')));
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
  const fallback=await preview(1440,{workspaceId:'two'}, {legacyWorkspace:true});
  try {
    assert.deepEqual(await fallback.page.evaluate(()=>window.workspaceRequests),['list']);
    await expect(fallback.page.getByRole('button',{name:'Brand assets',exact:true})).toHaveCount(0);
    await expect(fallback.page.getByRole('button',{name:'New workspace',exact:true})).toHaveCount(0);
    assert.ok(await fallback.page.evaluate(()=>window.testRequests.every(request=>request.workspace==='two')));
  } finally {await fallback.page.close();}
});

test('grid retains existing thumbnail nodes across resize and changes to list view', async () => {
  const {page,errors} = await preview(1440,{}, {files:60});
  try {
    const image=page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true}).locator('img');
    await image.evaluate(node=>{window.rememberedThumbnail=node;});
    const firstRow=page.locator('.fms-entry').first();
    const fileList=page.locator('.fms-scroll');
    const topGap=async()=>(await firstRow.boundingBox()).y-(await fileList.boundingBox()).y;
    await expect.poll(topGap).toBe(8);
    await page.setViewportSize({width:1024,height:900});
    await expect.poll(()=>page.locator('.fms-virtual').evaluate(node=>node.style.getPropertyValue('--fms-columns'))).toBe('3');
    assert.equal(await image.evaluate(node=>node===window.rememberedThumbnail),true);
    await page.getByRole('button',{name:'Layout',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'List view',exact:true}).click();
    await expect(page.locator('.fms-scroll')).toHaveClass(/is-list/);
    assert.equal(await image.evaluate(node=>node===window.rememberedThumbnail),true);
    const first=await page.locator('.fms-entry').nth(0).boundingBox(),second=await page.locator('.fms-entry').nth(1).boundingBox();
    assert.equal(second.y-first.y,64);
    await expect.poll(topGap).toBe(8);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('picker and library keep selected items directly below the sticky responsive filters', async () => {
  for(const mode of ['picker','library']){
    const {page,errors}=await preview(390,{mode,pickerSelectionLimit:3},{files:60,controls:true,organize:true,responsiveControls:true});
    try{
      const main=page.locator('.fms-main');
      const toolbar=main.locator('.fms-toolbar');
      const card=page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true});
      await card.hover();
      const checkbox=page.getByRole('checkbox',{name:'Select: Photo 000.svg',exact:true});
      const cardSelection=page.locator('.fms-entry').filter({has:checkbox}).locator('.fms-card-checkbox');
      await expect(cardSelection).toHaveCSS('opacity','1');
      await cardSelection.locator('.checkbox__control').click();
      await expect(checkbox).toBeChecked();
      const selection=main.locator('.fms-selection-bar');
      await expect(selection.getByRole('status')).toContainText('1 selected');
      await main.evaluate(el=>{el.scrollTop=560;});
      await expect.poll(()=>main.evaluate(el=>{
        const top=el.getBoundingClientRect().top;
        const toolbar=el.querySelector('.fms-toolbar').getBoundingClientRect();
        const selection=el.querySelector('.fms-selection-bar').getBoundingClientRect();
        return {scrolled:el.scrollTop>200,toolbarPinned:Math.abs(toolbar.top-top)<2,touching:Math.abs(toolbar.bottom-selection.top)<2};
      })).toEqual({scrolled:true,toolbarPinned:true,touching:true});
      await toolbar.getByRole('button',{name:'Filters',exact:true}).click();
      await expect(page.getByRole('dialog',{name:'Filters',exact:true})).toBeVisible();
      assert.deepEqual(errors,[]);
    }finally{await page.close();}
  }
});

test('folder picker resolves relative paths and the upload panel virtualizes and filters transfers', async () => {
  const {page,errors} = await preview(390,{}, {transfers:true,transferDelay:160,noRandomUUID:true});
  const root=await mkdtemp(join(tmpdir(),'fms-folder-'));
  try {
    const nested=join(root,'nested');
    await mkdir(nested);
    await Promise.all(Array.from({length:24},(_,index)=>writeFile(join(nested,`photo-${index}.txt`),'file')));
    const chooser=page.waitForEvent('filechooser');
    await page.locator('.fms-library-actions').getByRole('button',{name:'Create',exact:true}).click();
    await page.getByRole('menuitem',{name:'Upload folder',exact:true}).click();
    await (await chooser).setFiles(root);
    const panel=page.getByRole('complementary',{name:'Uploads'});
    await expect(panel).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.transferRequests.some(req=>req.paths?.some(path=>path.endsWith('/nested'))))).toBe(true);
    await expect(panel.getByRole('listitem').first()).toHaveAttribute('aria-setsize','24');
    assert.ok(await panel.getByRole('listitem').count()<24);
    assert.equal(await panel.evaluate(node=>Math.round(innerWidth-node.getBoundingClientRect().right)),12);
    await panel.getByRole('button',{name:'Minimize uploads',exact:true}).click();
    await expect(panel.getByRole('list')).toHaveCount(0);
    await panel.getByRole('button',{name:'Expand uploads',exact:true}).click();
    await panel.getByRole('tab',{name:'Completed',exact:true}).click();
    await expect(panel.getByRole('tab',{name:'Completed',exact:true})).toHaveAttribute('aria-selected','true');
    await expect(panel.getByRole('listitem').first()).toHaveAttribute('aria-setsize','24');
    await panel.getByRole('tab',{name:'Failed',exact:true}).click();
    await expect(panel.getByText('No uploads in this view.')).toBeVisible();
    await panel.getByRole('button',{name:'Clear finished',exact:true}).click();
    await expect(panel).toHaveCount(0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();await rm(root,{recursive:true,force:true});}
});


test('listing requests only thumbnails and detail uses its preview rendition while downloads keep the original', async () => {
  const {page,errors} = await preview(1440,{}, {files:3,previewVariants:true});
  const requests=[];
  page.on('request',request=>requests.push(new URL(request.url()).pathname));
  await page.route('**/rendition/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="green"/></svg>'}));
  await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',headers:{'Content-Disposition':'attachment; filename="original.svg"'},body:'<svg xmlns="http://www.w3.org/2000/svg"/>'}));
  try {
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[]);
    assert.ok(await page.locator('.fms-card img:not(.fms-file-type-icon)').evaluateAll(images=>images.every(image=>image.getAttribute('src').startsWith('/filament.svg?thumb='))));
    await page.getByRole('button',{name:'Layout',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'List view',exact:true}).click();
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[]);
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const dialog=page.getByRole('dialog');
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-original')).toHaveCSS('opacity','1');
    assert.equal(await dialog.locator('[data-preview-active="true"] .fms-preview-original').getAttribute('src'),'/rendition/000');
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[]);
    assert.deepEqual(requests.filter(path=>path.startsWith('/rendition/')),['/rendition/000']);
    const download=page.waitForEvent('download');
    await dialog.getByRole('button',{name:'Download',exact:true}).click();
    assert.equal((await download).suggestedFilename(),'original.svg');
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[{id:'000',download:true}]);
    await dialog.getByRole('button',{name:'Next file',exact:true}).click();
    await expect(dialog).toHaveAttribute('aria-label','Photo 001.svg');
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-placeholder')).toBeVisible();
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-original')).toHaveAttribute('src', '/original/001');
    await expect(dialog.locator('.fms-preview-spinner')).toHaveCount(0);
    await dialog.getByRole('button',{name:'Next file',exact:true}).click();
    await expect(dialog).toHaveAttribute('aria-label','Photo 002.svg');
    await expect(dialog.locator('[data-preview-active="true"] .fms-preview-original')).toHaveAttribute('src', '/original/002');
    assert.ok((await page.evaluate(()=>window.contentRequests)).some(request=>request.id==='002'&&!request.download));
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('a selected pending preview polls only its identity and switches to the completed rendition', async () => {
  const {page,errors} = await preview(1440,{}, {files:3,previewVariants:true,pendingPreview:true});
  await page.route('**/rendition/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"/>'}));
  try {
    await page.getByRole('button',{name:'Preview: Photo 001.svg',exact:true}).click();
    await expect(page.locator('[data-preview-active="true"] .fms-preview-placeholder')).toBeVisible();
    await expect(page.locator('[data-preview-active="true"] .fms-preview-original')).toHaveCount(0);
    await expect(page.locator('[data-preview-active="true"] .fms-preview-original')).toHaveCSS('opacity','1');
    assert.equal(await page.locator('[data-preview-active="true"] .fms-preview-original').getAttribute('src'),'/rendition/001');
    assert.deepEqual(await page.evaluate(()=>window.statusRequests),[{workspace:'one',ids:['001']}]);
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[]);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('visible cards poll pending previews after thumbnails are ready without downloading previews', async () => {
  const {page,errors} = await preview(1440,{}, {files:3,previewVariants:true,pendingPreview:true});
  const requested=[];
  page.on('request',request=>requested.push(new URL(request.url()).pathname));
  try {
    await expect.poll(()=>page.evaluate(()=>window.statusRequests),{timeout:5000}).toEqual([{workspace:'one',ids:['001']}]);
    assert.deepEqual(await page.evaluate(()=>window.contentRequests),[]);
    assert.equal(requested.some(path=>path.startsWith('/rendition/')||path.startsWith('/original/')),false);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('favorites toggle from card and preview while Recent records explicit openings only', async () => {
  const {page,errors} = await preview(1440,{}, {files:4,organize:true});
  try {
    const actions = page.getByRole('button',{name:'More actions: Photo 002.svg',exact:true});
    await expect(page.getByRole('menu',{name:'More actions',exact:true})).toHaveCount(0);
    await actions.click();
    await page.getByRole('menuitem',{name:'Add to favorites',exact:true}).click();
    await actions.click();
    await expect(page.getByRole('menuitem',{name:'Remove from favorites',exact:true})).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'Favorites',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Favorites');
    await expect(page.getByRole('button',{name:'Preview: Photo 001.svg',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Folder: Archive',exact:true}).click();
    await expect(page.locator('.fms-title-row h1')).toHaveText('Archive');
    assert.deepEqual(await page.evaluate(()=>window.testRequests.findLast(request=>request.query.view==='files').query),{view:'files',parent:'folder',search:''});
    await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'Favorites',exact:true}).click();
    await page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Photo 002.svg'});
    await dialog.getByRole('button',{name:'Remove from favorites: Photo 002.svg',exact:true}).click();
    await expect(dialog.getByRole('button',{name:'Add to favorites: Photo 002.svg',exact:true})).toBeVisible();
    await dialog.getByRole('button',{name:'Close',exact:true}).click();
    await expect(page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true})).toHaveCount(0);
    await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'Recent',exact:true}).click();
    await page.getByRole('button',{name:'Preview: Photo 002.svg',exact:true}).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button',{name:'Next file',exact:true})).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    assert.deepEqual(await page.evaluate(()=>window.visits),[{workspace:'one',id:'002'}]);
    assert.ok(await page.evaluate(()=>window.neighborRequests.some(request=>request.query.view==='recent'&&request.query.parent===null)));
    assert.deepEqual(await page.evaluate(()=>window.starRequests),[{workspace:'one',id:'002',starred:true},{workspace:'one',id:'002',starred:false}]);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('file card selection and more use matching circular controls and keep the selected check visible', async () => {
  const {page,errors} = await preview(1440,{}, {files:4,organize:true});
  try {
    const checkbox=page.getByRole('checkbox',{name:'Select: Photo 000.svg',exact:true});
    const card=page.locator('.fms-entry').filter({has:checkbox});
    const selection=card.locator('.fms-card-checkbox');
    const control=selection.locator('.checkbox__control');
    const more=card.getByRole('button',{name:'More actions: Photo 000.svg',exact:true});
    await expect(selection).toHaveCSS('opacity','0');
    await expect(more).toHaveCSS('opacity','0');
    await card.hover();
    await expect(selection).toHaveCSS('opacity','1');
    await expect(more).toHaveCSS('opacity','1');
    for(const button of [control,more]){
      const {width,height}=await button.boundingBox();
      assert.deepEqual({width,height},{width:32,height:32});
      await expect(button).toHaveCSS('border-radius','50%');
      await expect(button).toHaveCSS('color','rgb(255, 255, 255)');
    }
    for(const button of [control,more]){
      await expect(button).toHaveCSS('backdrop-filter','blur(12px) saturate(1.6) brightness(0.8)');
      await expect(button).toHaveCSS('background-color','rgba(20, 22, 28, 0.18)');
    }
    await expect(more).toHaveCSS('border-width','0px');
    await more.hover();
    await expect(more).toHaveCSS('background-color','rgba(20, 22, 28, 0.18)');
    await expect(control).toHaveCSS('box-shadow','none');
    await expect(more).toHaveCSS('box-shadow',/0px 0\.5px 0px 0px inset, .*0px 0px 0px 0\.5px inset, .*0px 2px 10px 0px$/);
    await expect(control).toHaveCSS('border','1px solid rgba(255, 255, 255, 0.35)');
    const checkmark=control.locator('[data-slot="checkbox-default-indicator--checkmark"]');
    await expect(checkbox).not.toBeChecked();
    await expect(checkmark).toHaveCSS('stroke-dasharray','none');
    await control.click();
    await expect(checkbox).toBeChecked();
    await page.mouse.move(0,0);
    await expect(selection).toHaveCSS('opacity','1');
    await expect(more).toHaveCSS('opacity','0');
    assert.equal(await control.evaluate(element=>getComputedStyle(element,'::before').content),'none');
    await expect(control).toHaveCSS('background-color','rgb(8, 102, 255)');
    await card.hover();
    await control.click();
    await expect(checkbox).not.toBeChecked();
    await page.mouse.move(0,0);
    await expect(selection).toHaveCSS('opacity','0');
    await expect(more).toHaveCSS('opacity','0');

    await card.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).focus();
    await page.keyboard.press('Tab');
    await expect(checkbox).toBeFocused();
    await expect(selection).toHaveCSS('opacity','1');
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('selected rows move, trash and restore using captured revisions and survive a failed action', async () => {
  const {page,errors} = await preview(1440,{}, {files:5,organize:true});
  try {
    const select=(id)=>page.getByRole('checkbox',{name:`Select: Photo ${id}.svg`,exact:true});
    const group=page.getByRole('group',{name:'Selection',exact:true});
    await expect(select('002')).toHaveCount(0);
    await select('000').press('Space');
    const secondCard=page.locator('.fms-entry').filter({has:select('001')});
    await secondCard.hover();
    await secondCard.locator('.checkbox__content').click();
    await expect(group.getByRole('status')).toHaveText('2 selected');
    await group.getByRole('button',{name:'Move',exact:true}).click();
    const destination=page.getByRole('dialog',{name:'Move to…'});
    await expect(destination).toBeVisible();
    await expect(destination.getByRole('checkbox',{name:'Archive',exact:true})).toBeVisible();
    await destination.getByRole('checkbox',{name:'Archive',exact:true}).click();
    await destination.getByRole('button',{name:'Move',exact:true}).click();
    await expect(destination).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Folder: Archive',exact:true}).click();
    await page.getByRole('button',{name:'Select loaded',exact:true}).click();
    await expect(group.getByRole('status')).toHaveText('2 selected');
    await page.evaluate(()=>{window.failBulk=true;});
    await group.getByRole('button',{name:'Move to trash',exact:true}).click();
    const trash=page.getByRole('dialog',{name:'Move selected items to trash?'});
    await trash.getByRole('button',{name:'Move to trash',exact:true}).click();
    await expect(trash.getByRole('alert')).toHaveText('The selection changed. Try again.');
    await trash.getByRole('button',{name:'Move to trash',exact:true}).click();
    await expect(trash).toHaveCount(0);
    await page.getByRole('button',{name:'Trash',exact:true}).click();
    await page.getByRole('button',{name:'Select loaded',exact:true}).click();
    await group.getByRole('button',{name:'Restore',exact:true}).click();
    const restore=page.getByRole('dialog',{name:'Restore selected items?'});
    await restore.getByRole('button',{name:'Restore',exact:true}).click();
    await expect(restore).toHaveCount(0);
    await expect(group).toHaveCount(0);
    const calls=await page.evaluate(()=>window.bulkRequests);
    assert.deepEqual(calls.map(({operation,items})=>[operation,items.map(item=>item.revision)]),[['move',[1,1]],['trash',[2,2]],['trash',[2,2]],['restore',[3,3]]]);
    assert.equal(calls[0].parent,'folder');
    assert.ok(calls.every(call=>call.workspace==='one'&&call.items.map(item=>item.id).join(',')==='000,001'));
    await page.getByRole('button',{name:'Account menu',exact:true}).click();
    await page.getByRole('menuitem',{name:'Campaign',exact:true}).click();
    await expect(page.getByRole('button',{name:'Select loaded',exact:true})).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('select loaded caps selection at one hundred and clears it on request', async () => {
  const {page,errors} = await preview(1440,{}, {files:120,organize:true});
  try {
    const select=page.getByRole('button',{name:'Select loaded',exact:true});
    const group=page.getByRole('group',{name:'Selection',exact:true});
    await select.click();
    await expect(group.getByRole('status')).toHaveText('100 selected');
    await expect(select).toBeDisabled();
    await group.getByRole('button',{name:'Clear selection',exact:true}).click();
    await expect(group).toHaveCount(0);
    await expect(select).toBeEnabled();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


async function openDateAndSize(page){
 const hub=page.locator('.fms-filter-hub');
 const collapsed=page.locator('.fms-responsive-filters[data-compact]');
 if(await collapsed.count()&&!await hub.count())await collapsed.getByRole('button',{name:'Filters',exact:true}).click();
 await page.getByRole('button',{name:'Date & size',exact:true}).click();
}

test('browse filters apply and clear while sort direction reaches the same preview neighbor scope', async () => {
  const {page,errors} = await preview(390,{}, {files:4,controls:true});
  try {
    const open=page.getByRole('button',{name:'Date & size',exact:true});
    await openDateAndSize(page);
    const dialog=page.getByRole('dialog',{name:'Date & size',exact:true});
    await expect(dialog).toBeVisible();
    assert.equal(await dialog.evaluate(element=>element.getRootNode() instanceof ShadowRoot),true);
    await dialog.getByRole('button',{name:'Last 7 days',exact:true}).click();
    await expect(dialog.locator('[data-slot="date-picker"]')).toHaveCount(2);
    const today=new Date();const from=new Date(today);from.setUTCDate(from.getUTCDate()-6);
    await expect(dialog.getByRole('spinbutton',{name:'year, From date',exact:true})).toHaveText(String(from.getUTCFullYear()));
    await dialog.getByLabel('Minimum size (MiB)').fill('2');
    await dialog.getByLabel('Maximum size (MiB)').fill('1');
        await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect(dialog.getByRole('alert')).toBeVisible();
    await dialog.getByLabel('Maximum size (MiB)').fill('3.5');
    await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect(dialog).toHaveCount(0);
    await page.getByRole('button',{name:'Type',exact:true}).click();
    const types=page.getByRole('dialog',{name:'Type',exact:true});
    await types.getByRole('option',{name:'Images',exact:true}).click();
    await page.keyboard.press('Escape');await expect(types).toHaveCount(0);
    const filters={dateFrom:from.toISOString().slice(0,10),dateTo:today.toISOString().slice(0,10),minBytes:2097152,maxBytes:3670016,types:['image']};
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.filters)).toEqual(filters);
    if(await page.locator('.fms-filter-hub').count())await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'Sort by: Name, Ascending',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'File size',exact:true}).click();
    await page.getByRole('button',{name:'Sort by: File size, Ascending',exact:true}).click();
    await page.getByRole('menuitemradio',{name:/^File size/}).click();
    await expect(page.getByRole('button',{name:'Sort by: File size, Descending',exact:true})).toBeVisible();
    const scoped={parent:null,view:'files',search:'',filters,sort:'size',direction:'desc'};
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query)).toEqual(scoped);
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const preview=page.getByRole('dialog',{name:'Photo 000.svg'});
    await expect(preview).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>window.neighborRequests[0]?.query)).toEqual(scoped);
    await page.keyboard.press('Escape');
    await expect(preview).toHaveCount(0);
    await openDateAndSize(page);
    await dialog.getByRole('button',{name:'Clear filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query)).toEqual({parent:null,view:'files',search:'',sort:'size',direction:'desc'});
    if(await page.locator('.fms-filter-hub').count())await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'Open navigation',exact:true}).click();
    await page.getByRole('navigation',{name:'Library',exact:true}).getByRole('button',{name:'Photos',exact:true}).click();
    await expect(open).toHaveCount(0);
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query)).toEqual({parent:null,view:'photos',search:'',photos:{kind:'all',period:'days'}});
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('custom browse filters and sorters use configured labels and preserve the native query scope', async()=>{
  const options={browse:{
    filters:[{id:'audience',label:'Audience',options:[{id:'members & guests',label:'Members and guests'},{id:'staff',label:'Staff'}]}],
    sorters:[{id:'priority',label:'Editorial priority'}],
  }};
  const {page,errors}=await preview(1440,options,{files:4,controls:true});
  try{
    await openDateAndSize(page);
    const filters=page.getByRole('dialog',{name:'Date & size',exact:true});
    await filters.locator('[data-slot=select-trigger]').click();
    const listbox=page.getByRole('listbox',{name:'Audience',exact:true});
    await expect(listbox).toBeVisible();
    assert.equal(await listbox.evaluate(element=>element.getRootNode() instanceof ShadowRoot),true);
    await listbox.getByRole('option',{name:'Members and guests',exact:true}).click({timeout:3000});
    await filters.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.customFilters)).toEqual({audience:'members & guests'});
    await page.getByRole('button',{name:/^Sort by:/}).click();
    await page.getByRole('menuitemradio',{name:'Editorial priority',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.sort)).toBe('priority');
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.neighborRequests[0]?.query.customFilters)).toEqual({audience:'members & guests'});
    await page.keyboard.press('Escape');
    await openDateAndSize(page);
    await filters.getByRole('button',{name:'Clear filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.customFilters)).toBeUndefined();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('audio preview loads Video.js on demand and releases playback on close',async()=>{
  const {page,errors}=await preview(1440,{}, {files:1,audio:true});
  const artwork=page.locator('.fms-audio-artwork');
  await expect(artwork.locator('.fms-audio-waveform')).toBeVisible();
  await expect(artwork.locator('svg')).toHaveCount(1);
  const artworkBounds=await artwork.boundingBox();
  const waveformBounds=await artwork.locator('.fms-audio-waveform').boundingBox();
  assert.ok(waveformBounds.width>artworkBounds.width*.8,'audio artwork spans the card before measured peaks arrive');
  const samples=24000;
  const wav=Buffer.alloc(44+samples*2);
  wav.write('RIFF',0);wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);
  wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);
  wav.writeUInt32LE(8000,24);wav.writeUInt32LE(16000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);
  wav.write('data',36);wav.writeUInt32LE(samples*2,40);
  await page.route('**/original/000',route=>route.fulfill({contentType:'audio/wav',body:wav}));
  try{
    assert.equal(await page.evaluate(()=>!!customElements.get('audio-player')),false);
    await page.getByRole('button',{name:'Preview: Recording.wav',exact:true}).click();
    const skin=page.locator('audio-skin');
    const play=skin.getByRole('button',{name:'Play',exact:true});
    await expect(play).toBeVisible();
    await expect(skin.locator('media-time-slider')).toBeVisible();
    const audio=await skin.locator('audio').elementHandle();
    assert.equal(await audio.evaluate(element=>element.controls),false);
    await play.click();
    await expect.poll(()=>audio.evaluate(element=>element.currentTime)).toBeGreaterThan(0);
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await expect.poll(()=>audio.evaluate(element=>({paused:element.paused,source:element.getAttribute('src')}))).toEqual({paused:true,source:null});
    await audio.dispose();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('video reserves its frame while loading and reveals playback without moving the surrounding layout',async()=>{
  const {page,errors}=await preview(1440,{}, {files:1,video:true});
  let release;const gate=new Promise(resolve=>{release=resolve;});
  const video=await readFile(new URL('../fixtures/video-loading.webm',import.meta.url));
  await page.route('**/original/000',async route=>{await gate;await route.fulfill({contentType:'video/webm',body:video});});
  try{
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const frame=page.locator('.fms-playback-preview.is-video');
    await expect(frame).toHaveAttribute('data-loading','true');
    await expect(page.locator('.fms-video-loading')).toBeVisible();
    const before=await frame.boundingBox();const stage=await page.locator('.fms-preview-stage').boundingBox();
    assert.ok(before.x-stage.x>=63);assert.ok(stage.x+stage.width-before.x-before.width>=63);
    release();
    await expect(page.locator('.fms-video-loading')).toHaveCount(0);
    await expect(page.locator('.fms-video-player')).toHaveCSS('opacity','1');
    const after=await frame.boundingBox();
    const skin=await page.locator('.fms-video-player').boundingBox();
    assert.ok(Math.abs(skin.width/skin.height-4/3)<.01,'Player must adopt the actual video aspect ratio');
    await expect(page.locator('.fms-video-player')).not.toHaveAttribute('content-title');
    const skinAppearance=await page.locator('video-skin').evaluate(el=>{const surface=el.shadowRoot.querySelector('.media-skin');return {gradient:getComputedStyle(surface).getPropertyValue('--media-controls-top-gradient').trim(),titleHidden:surface.querySelector('.media-title').hidden};});
    assert.match(skinAppearance.gradient,/linear-gradient/,'Video.js controls must retain their original contrast gradient');assert.equal(skinAppearance.titleHidden,true);
    for(const key of ['x','y','width','height'])assert.ok(Math.abs(before[key]-after[key])<.1,`Player ${key} shifted`);
    const media = await page.locator('.fms-video-player video').elementHandle();
    await page.getByRole('button', {name:'Close', exact:true}).click();
    await expect.poll(() => media.evaluate(video => ({connected: video.isConnected, paused: video.paused, source: video.getAttribute('src')})))
      .toEqual({connected:false, paused:true, source:null});
    await media.dispose();
    assert.deepEqual(errors,[]);
  }finally{release();await page.close();}
});

test('image drawing saves a separate overlay that can be hidden and restored', async () => {
  const {page,errors} = await preview(1440, {}, {files: 3, drawing: true, detailsDelay: 300});
  await page.route('**/original/000', route => route.fulfill({
    contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#eee"/></svg>',
  }));
  try {
    await page.getByRole('button', {name: 'Preview: Photo 000.svg', exact: true}).click();
    await page.getByRole('button', {name: 'Draw on the image', exact: true}).click();
    const surface = page.locator('.fms-drawing-surface');
    await expect(surface).toBeVisible();
    const box = await surface.boundingBox();
    await page.mouse.move(box.x + box.width * .55, box.y + box.height * .4);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * .7, box.y + box.height * .6, {steps: 8});
    await page.mouse.up();
    await page.getByRole('button', {name: 'Save drawing', exact: true}).click();
    await expect(surface).toHaveCount(0);
    await expect(page.locator('.fms-drawing-overlay path')).toHaveCount(1);
    await page.getByRole('button', {name: 'Hide annotations', exact: true}).click();
    await expect(page.locator('.fms-drawing-overlay')).toHaveCount(0);
    await page.getByRole('button', {name: 'Show annotations', exact: true}).click();
    await expect(page.locator('.fms-drawing-overlay path')).toHaveCount(1);
    const eye=page.getByRole('button',{name:'Hide annotations',exact:true});
    const retained=await eye.elementHandle();
    await page.getByRole('button',{name:'Next file',exact:true}).click();
    await expect(eye).toBeDisabled();
    await expect(eye).toHaveCSS('opacity','1');
    assert.equal(await retained.evaluate(el=>el.isConnected),true);
    await expect(eye).toHaveCount(0);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('drawing text is entered at its image position and can be edited again', async () => {
  const {page, errors} = await preview(1440, {}, {files:1,drawing:true});
  await page.route('**/original/000', route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#eee"/></svg>'}));
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await page.getByRole('button',{name:'Draw on the image',exact:true}).click();
    await page.getByRole('button',{name:'Text',exact:true}).click();
    await page.getByRole('button',{name:'Styles',exact:true}).click();
    await page.getByRole('button',{name:'Align center',exact:true}).click();
    await page.getByRole('button',{name:'Styles',exact:true}).click();
    const surface=page.locator('.fms-drawing-surface');
    await surface.click({position:{x:220,y:150}});
    const input=page.locator('.fms-drawing-inline-text');
    await expect(input).toBeFocused();
    const emptyTextBox=await input.boundingBox();
    assert.ok(emptyTextBox.width<=24,'empty text starts as a small caret box');
    await input.fill('Review this image');
    const populatedTextBox=await input.boundingBox(), imageBox=await surface.boundingBox();
    assert.ok(populatedTextBox.width>emptyTextBox.width,'text box grows with its content');
    assert.ok(populatedTextBox.width<imageBox.width*.6,'short text does not span the canvas');
    const editingRect=await input.boundingBox();
    await input.press('Enter');
    await expect(input).toHaveCount(0);
    const renderedRect=await surface.locator('.fms-drawing-text-content').boundingBox();
    assert.ok(Math.abs(editingRect.x-renderedRect.x)<1,'text keeps its horizontal anchor');
    assert.ok(Math.abs(editingRect.y-renderedRect.y)<1,'text keeps its vertical anchor');
    assert.ok(Math.abs(editingRect.height-renderedRect.height)<1,'editing and display use the same line metrics');
    await expect(surface.locator('.fms-drawing-text-content')).toHaveText('Review this image');
    await surface.locator('.fms-drawing-text-content').click();
    await expect(input).toHaveValue('Review this image');
    await expect(surface.locator('[data-mark]')).toHaveCount(1);
    await input.fill('Updated review');
    await input.press('Enter');
    await page.getByRole('button',{name:'Save drawing',exact:true}).click();
    await expect(page.locator('.fms-drawing-overlay .fms-drawing-text-content')).toHaveText('Updated review');
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('horizontal wheel over the inspector never switches the selected photo', async () => {
  const {page,errors}=await preview(1440,{}, {files:3});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const inspector=page.locator('.fms-preview-layout>.fms-inspector');
    await expect(inspector).toBeVisible();
    await inspector.dispatchEvent('wheel',{deltaX:120,deltaY:0,bubbles:true});
    await expect(page.getByRole('dialog').getByText('Photo 000',{exact:true}).first()).toBeVisible();
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('drawing style panel adapts to the tool and preserves styled shapes on save', async () => {
  const {page,errors}=await preview(1440,{}, {files:1,drawing:true});
  await page.route('**/original/000',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#eee"/></svg>'}));
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await page.getByRole('button',{name:'Draw on the image',exact:true}).click();
    await page.getByRole('button',{name:'Text',exact:true}).click();
    await page.getByRole('button',{name:'Styles',exact:true}).click();
    await expect(page.getByRole('toolbar',{name:'Font',exact:true})).toBeVisible();
    await expect(page.locator('.fms-drawing-styles')).toHaveCSS('width','148px');
    await page.getByRole('button',{name:'Pen',exact:true}).click();
    await expect(page.locator('.fms-drawing-styles')).toHaveCSS('height','286px');
    await page.getByRole('button',{name:'Text',exact:true}).click();
    await expect(page.getByRole('toolbar',{name:'Fill',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Shapes: Rectangle',exact:true}).click();await page.getByRole('button',{name:'Rectangle',exact:true}).click();
    await expect(page.getByRole('toolbar',{name:'Fill',exact:true})).toBeVisible();await expect(page.getByRole('toolbar',{name:'Font',exact:true})).toHaveCount(0);await expect(page.getByRole('toolbar',{name:'Alignment',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Solid fill',exact:true}).click();
    await page.getByRole('button',{name:'Dashed',exact:true}).click();
    await page.getByRole('button',{name:'Styles',exact:true}).click();
    const surface=page.locator('.fms-drawing-surface'), box=await surface.boundingBox();
    await page.mouse.move(box.x+box.width*.4,box.y+box.height*.4);await page.mouse.down();
    await page.mouse.move(box.x+box.width*.65,box.y+box.height*.65,{steps:5});await page.mouse.up();await expect(surface).toHaveAttribute('data-tool','select');await expect(page.getByRole('button',{name:'Select',exact:true})).toHaveAttribute('aria-pressed','true');
    await page.getByRole('button',{name:'Save drawing',exact:true}).click();
    await expect(page.locator('.fms-drawing-overlay path')).toHaveAttribute('fill','#4169e1');
    await expect(page.locator('.fms-drawing-overlay path')).toHaveAttribute('stroke-dasharray','9 6');
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('image actions keep the same toolbar and width while the next image details load', async () => {
  const {page,errors}=await preview(1440,{}, {files:3,detailsDelay:300});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const draw=page.getByRole('button',{name:'Draw on the image',exact:true});
    await expect(draw).toBeEnabled();
    await expect(draw.locator('svg')).toHaveCSS('width','24px');
    await expect(draw.locator('svg')).toHaveAttribute('stroke-width','2.25');
    await expect(page.getByRole('button',{name:'Hide annotations',exact:true})).toHaveCount(0);
    const bar=page.getByRole('toolbar',{name:'File actions',exact:true});
    await expect(bar).toHaveCSS('width','144px');
    const original=await bar.elementHandle();
    await page.getByRole('button',{name:'Next file',exact:true}).click();
    await expect(draw).toBeVisible();
    await expect(draw).toBeDisabled();
    await expect(draw).toHaveCSS('opacity','1');
    await expect(bar).toHaveCSS('width','144px');
    await expect(draw).toBeEnabled();
    assert.equal(await original.evaluate(el=>el.isConnected),true,'toolbar is retained across image navigation');
    await expect(page.getByRole('button',{name:'Hide annotations',exact:true})).toHaveCount(0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('file comments support team mentions, resolving and confirmed deletion', async()=>{
  const {page,errors}=await preview(1440,{}, {files:1,comments:true});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await page.getByRole('tab',{name:'Comments',exact:true}).click();
    await expect(page.locator('.fms-inspector-header')).toHaveCSS('height','56px');
    const input=page.getByRole('textbox',{name:'Add a comment',exact:true});
    const geometry=await page.locator('.fms-comment-composer').evaluate(el=>{
      const p=el.closest('.fms-inspector').getBoundingClientRect(),r=el.getBoundingClientRect(),g=el.querySelector('.fms-comment-input').getBoundingClientRect(),b=el.querySelector('button[type=submit]').getBoundingClientRect();
      return {bottomGap:p.bottom-r.bottom,inside:b.left>=g.left&&b.right<=g.right&&b.top>=g.top&&b.bottom<=g.bottom};
    });
    assert.ok(geometry.bottomGap>=0&&geometry.bottomGap<=24,'composer stays at the bottom of the panel');
    assert.equal(geometry.inside,true,'send arrow is inside the input group');
    await expect(page.locator('.fms-comment-input')).toHaveCSS('flex-direction','row');
    await expect(page.locator('.fms-comment-input')).toHaveCSS('height','42px');
    await page.getByRole('button',{name:'Filter comments',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'Open',exact:true}).click();
    await expect(page.getByRole('button',{name:'Filter comments',exact:true})).toHaveClass(/button--primary/);
    await page.getByRole('button',{name:'Filter comments',exact:true}).click();
    await page.getByRole('menuitemradio',{name:'All',exact:true}).click();
    await input.fill('Please review @Al');
    await expect(page.getByRole('listbox',{name:'Mention a teammate',exact:true})).toBeVisible();
    await expect(page.getByRole('option',{name:'A Alex',exact:true})).toBeVisible();
    await input.press('Enter');
    await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect(page.locator('.fms-comment p')).toHaveText('Please review @Alex ');
    await expect(page.locator('.fms-comment-mention')).toHaveText('@Alex');
    assert.deepEqual(await page.evaluate(()=>window.commentItems[0].mentions),['member-1']);
    await page.getByRole('button',{name:'Resolve',exact:true}).click();
    await expect(page.getByRole('button',{name:'Reopen',exact:true})).toHaveAttribute('data-active','true');
    await expect(page.locator('.fms-comment p')).toHaveCSS('text-decoration-line','line-through');
    await expect(page.locator('.fms-comment small')).toHaveCount(0);
    const actions=page.getByRole('button',{name:'Comment actions',exact:true});
    await actions.click();
    const menu=page.getByRole('menu',{name:'Comment actions',exact:true});
    await expect(menu).toBeVisible();
    const triggerBounds=await actions.boundingBox(), menuBounds=await menu.boundingBox();
    assert.ok(menuBounds.x<triggerBounds.x&&menuBounds.x+menuBounds.width<=triggerBounds.x+triggerBounds.width+1,'comment menu extends left from the trigger');
    await menu.getByRole('menuitem',{name:'Delete',exact:true}).click();
    const confirmation=page.getByRole('dialog',{name:'Delete comment?',exact:true});
    await expect(confirmation).toBeVisible();
    await confirmation.getByRole('button',{name:'Delete',exact:true}).click();
    await expect(page.locator('.fms-comment')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('comments use cursor infinite loading, virtual rows and a sticky information header', async()=>{
  const {page,errors}=await preview(1440,{}, {files:1,comments:true,commentCount:420});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const inspector=page.getByRole('region',{name:'File information',exact:true});
    const tabs=page.getByRole('tablist',{name:'File information',exact:true});
    const start=await tabs.boundingBox();
    const gap=await inspector.locator('.fms-file-facts').first().evaluate(el=>el.getBoundingClientRect().top-el.parentElement.previousElementSibling.getBoundingClientRect().bottom);
    assert.ok(gap>=16,'file information has breathing room under its header');
    await inspector.evaluate(el=>el.scrollTop=200);
    assert.equal(Math.round((await tabs.boundingBox()).y),Math.round(start.y));
    await page.getByRole('tab',{name:'Comments',exact:true}).click();
    const feed=page.getByRole('feed',{name:'Comments',exact:true});
    await expect(feed.locator('.fms-comment').first()).toBeVisible();
    for(let batch=1;batch<=10;batch++){
      await feed.evaluate(el=>el.scrollTop=el.scrollHeight);
      await expect.poll(()=>page.evaluate(()=>window.commentRequests.length)).toBeGreaterThanOrEqual(batch+1);
      await expect(feed).toHaveAttribute('aria-busy','false');
    }
    assert.ok(await feed.locator('.fms-comment').count()<25,'only visible rows plus overscan stay mounted');
    assert.ok((await page.evaluate(()=>window.commentRequests)).includes('300'),'later pages load by cursor');
    await feed.evaluate(el=>el.scrollTop=0);
    await expect(feed.getByText(/^Comment 0 /)).toBeVisible();
    assert.ok((await page.evaluate(()=>window.commentRequests.filter(c=>c===null).length))>=2,'evicted first page is refetched on return');
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('preview actionbar keeps download first and comments last after a separator',async()=>{
  const {page,errors}=await preview(1440,{}, {files:1,comments:true});
  try {
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    const bar=page.getByRole('toolbar',{name:'File actions',exact:true});
    const labels=await bar.getByRole('button').evaluateAll(buttons=>buttons.map(b=>b.getAttribute('aria-label')));
    assert.equal(labels[0],'Download');assert.equal(labels.at(-1),'Comments');
    await expect(bar.locator('.fms-preview-action-slot').last().getByRole('separator')).toHaveCount(1);
    await expect(page.locator('.fms-preview-header').getByRole('button',{name:'Comments',exact:true})).toHaveCount(0);
    await expect(page.locator('.fms-preview-header').getByRole('button',{name:'Download',exact:true})).toHaveCount(0);
    await bar.getByRole('button',{name:'Comments',exact:true}).click();
    await expect(page.getByRole('tab',{name:'Comments',exact:true})).toHaveAttribute('aria-selected','true');
    await expect(page.getByRole('textbox',{name:'Add a comment',exact:true})).toBeVisible();
    assert.deepEqual(errors,[]);
  }finally {await page.close();}
});

test('Studio toasts show newly arrived inbox notifications once in the top-right corner', async()=>{
  const {page,errors}=await preview(1440,{}, {inboxToasts:true});
  try {
    await expect.poll(()=>page.evaluate(()=>window.inboxRequests?.length??0)).toBe(1);
    const region=page.locator('.fms-root > .fms-toast-region');
    await expect(region).toHaveCount(0);
    await page.evaluate(()=>{
      window.inboxItems.unshift({id:'new',event:'mentioned',name:'New mention',readAt:null});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(region.getByText('New mention',{exact:true})).toBeVisible();
    await expect(region.getByText('Previous mention',{exact:true})).toHaveCount(0);
    const bounds=await region.boundingBox();
    assert.ok(bounds.y<32&&Math.abs(bounds.x+bounds.width-(1440-16))<2,'toasts align with the top-right edge of Studio');
    await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
    await expect.poll(()=>page.evaluate(()=>window.inboxRequests.length)).toBe(3);
    await expect(region.getByText('New mention',{exact:true})).toHaveCount(1);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

async function beginFileMove(page, id) {
  const box=await page.locator('[data-move-entry="'+id+'"] .fms-card').boundingBox();
  await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
  await page.mouse.down();
  await page.mouse.move(box.x+box.width/2+15,box.y+box.height/2+15,{steps:4});
  await expect(page.locator('.fms-move-drop-bar')).toBeVisible();
}

test('dragging selected files onto a folder highlights it and moves the group once', async()=>{
  const {page,errors}=await preview(1440,{}, {files:4,organize:true});
  try {
    await page.getByRole('checkbox',{name:'Select: Photo 000.svg',exact:true}).press('Space');
    await page.getByRole('checkbox',{name:'Select: Photo 001.svg',exact:true}).press('Space');
    await beginFileMove(page,'000');
    const folder=page.locator('[data-move-folder="folder"]');
    const box=await folder.boundingBox();
    await page.mouse.move(box.x+box.width/2,box.y+box.height/2,{steps:8});
    await expect(folder).toHaveAttribute('data-move-over','true');
    await page.mouse.up();
    await expect(page.locator('.fms-move-drop-bar')).toHaveCount(0);
    await expect.poll(()=>page.evaluate(()=>window.bulkRequests.length)).toBe(1);
    const requests=await page.evaluate(()=>window.bulkRequests);
    const {requestId,...move}=requests[0];
    assert.match(requestId,/^[0-9a-f]{8}-[0-9a-f-]{27}$/i);
    assert.deepEqual(move,{workspace:'one',operation:'move',items:[{id:'000',revision:1},{id:'001',revision:1}],parent:'folder'});
    await expect(page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Preview: Photo 001.svg',exact:true})).toHaveCount(0);
    await expect(page.locator('.fms-preview-surface')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('the drag move bar opens the existing destination modal before moving', async()=>{
  const {page,errors}=await preview(1440,{}, {files:3,organize:true});
  try {
    await beginFileMove(page,'000');
    const bar=page.locator('.fms-move-drop-bar');
    const box=await bar.boundingBox();
    await page.mouse.move(box.x+box.width/2,box.y+box.height/2,{steps:8});
    await expect(bar).toHaveAttribute('data-move-over','true');
    await page.mouse.up();
    const dialog=page.getByRole('dialog',{name:'Move to…'});
    await expect(dialog).toBeVisible();
    assert.equal(await page.evaluate(()=>window.bulkRequests.length),0);
    await dialog.getByRole('checkbox',{name:'Archive',exact:true}).click();
    await dialog.getByRole('button',{name:'Move',exact:true}).click();
    await expect(dialog).toHaveCount(0);
    await expect.poll(()=>page.evaluate(()=>window.bulkRequests.length)).toBe(1);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('dragging rejects self drops and Escape cancels without opening a preview', async()=>{
  const {page,errors}=await preview(1440,{}, {files:3,organize:true});
  try {
    await beginFileMove(page,'folder');
    await expect(page.locator('[data-move-folder="folder"]')).not.toHaveAttribute('data-move-over','true');
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(page.locator('.fms-move-drop-bar')).toHaveCount(0);
    await expect(page.locator('.fms-move-ghost')).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.bulkRequests.length),0);
    await expect(page.locator('.fms-preview-surface')).toHaveCount(0);
    await page.getByRole('button',{name:'Campaign',exact:true}).click();
    await expect(page.locator('[data-move-entry]')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('failed folder drops keep the file available for retry', async()=>{
  const {page,errors}=await preview(1440,{}, {files:3,organize:true});
  try {
    await page.evaluate(()=>{window.failBulk=true;});
    for(let attempt=0;attempt<2;attempt++){
      await beginFileMove(page,'000');
      const box=await page.locator('[data-move-folder="folder"]').boundingBox();
      await page.mouse.move(box.x+box.width/2,box.y+box.height/2,{steps:6});
      await page.mouse.up();
      if(attempt===0){
        await expect(page.getByRole('alert')).toContainText('The selection changed. Try again.');
        await expect(page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true})).toBeVisible();
      }
    }
    await expect(page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true})).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.bulkRequests.length),2);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('file dragging scrolls the virtual viewport near its edge', async()=>{
  const {page,errors}=await preview(1440,{}, {files:120,organize:true});
  try {
    await beginFileMove(page,'000');
    const viewport=page.locator('.fms-scroll');
    const box=await viewport.boundingBox();
    await page.mouse.move(box.x+box.width-10,box.y+box.height-5,{steps:8});
    await expect.poll(()=>viewport.evaluate(element=>element.scrollTop)).toBeGreaterThan(100);
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(page.locator('.fms-move-ghost')).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.bulkRequests.length),0);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('assistant stays optional and streams saved chats with a preview context',async()=>{
  const disabled=await preview(1440,{}, {assistant:'disabled'});
  await expect(disabled.page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true})).toHaveCount(0);
  await disabled.page.close();
  const {page,errors}=await preview(1440,{}, {assistant:true,files:2});
  await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
  await expect(page.getByRole('complementary',{name:'Chat history',exact:true})).toBeVisible();
  await expect(page.getByRole('complementary',{name:'AI assistant'})).toBeHidden();
  await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
  await expect(page.getByRole('complementary',{name:'Chat history',exact:true})).toHaveCount(0);
  await expect(page.getByRole('complementary',{name:'AI assistant'})).toBeVisible();
  await page.getByRole('button',{name:'Customize',exact:true}).click();
  await expect(page.getByRole('dialog',{name:'Customize assistant',exact:true})).toBeVisible();
  await expect(page.locator('.fms-assistant-customize-dialog .fc-small')).toHaveCount(0);
  await expect(page.locator('.fms-assistant-customize-dialog .fc-agentbar')).toHaveCount(0);
  await page.getByRole('tab',{name:'Face',exact:true}).click();
  const creatorOptions=page.locator('.fms-assistant-customize-dialog .fc-options');
  await creatorOptions.hover();await page.mouse.wheel(0,500);
  await expect.poll(()=>creatorOptions.evaluate(el=>el.scrollTop)).toBeGreaterThan(100);
  await page.getByRole('tab',{name:'Shape',exact:true}).click();
  await page.getByRole('button',{name:'Edit name',exact:true}).click();
  await page.getByRole('textbox',{name:'Assistant name',exact:true}).fill('Nova');
  await page.getByRole('button',{name:'shape: hexagon',exact:true}).click();
  await page.getByRole('button',{name:'Save',exact:true}).click();
  await expect(page.locator('.fms-assistant-profile-trigger')).toContainText('Nova');
  assert.equal(await page.evaluate(()=>window.assistantProfile.character.shape),'hexagon');
  const trigger=page.locator('.fms-assistant-model .select__trigger');
  await expect(trigger).toBeVisible();
  const valueBox=await page.locator('.fms-assistant-model-value').boundingBox(),indicatorBox=await page.locator('.fms-assistant-model-indicator').boundingBox();
  assert.ok(valueBox.x+valueBox.width<=indicatorBox.x-3);
  const toolbar=await page.locator('.fms-assistant-model').boundingBox(),newChat=await page.getByRole('button',{name:'New AI chat',exact:true}).boundingBox();
  assert.ok(newChat.y>=toolbar.y&&newChat.y+newChat.height<=toolbar.y+toolbar.height+1);
  await page.locator('.fms-assistant-file-input').setInputFiles({name:'brief.txt',mimeType:'text/plain',buffer:Buffer.from('Test attachment')});
  await expect(page.locator('.fms-assistant-attachments')).toContainText('brief.txt');
  await page.getByRole('button',{name:'Actions',exact:true}).click();
  await page.getByRole('menuitem',{name:'Summarize file',exact:true}).click();
  await expect(page.getByRole('textbox',{name:'Message the assistant'})).toHaveValue(/Summarize/);
  await page.getByRole('textbox',{name:'Message the assistant'}).fill('Hello there');
  await page.getByRole('button',{name:'Send',exact:true}).click();
  await expect(page.locator('.fms-assistant-message.is-assistant p')).toHaveText('Streamed response');
  assert.equal(await page.evaluate(()=>window.chatInput.text),'Hello there');
  assert.deepEqual(await page.evaluate(()=>window.chatInput.attachments),['attachment-one']);
  await expect(page.locator('.fms-assistant-usage')).toHaveText('15 tokens');
  await mkdir(new URL('../../.internal/assistant-20261111/',import.meta.url),{recursive:true});
  await page.screenshot({path:fileURLToPath(new URL('../../.internal/assistant-20261111/chat.png',import.meta.url))});
  await page.getByRole('button',{name:'Chat history',exact:true}).click();
  await page.getByRole('button',{name:'Chat options: Saved conversation',exact:true}).click();
  await page.getByRole('menuitem',{name:'Rename',exact:true}).click();
  await page.getByRole('textbox',{name:'Chat name',exact:true}).fill('Renamed conversation');
  await page.getByRole('button',{name:'Save',exact:true}).click();
  await expect(page.getByRole('button',{name:'Renamed conversation',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Chat options: Renamed conversation',exact:true}).click();
  await page.getByRole('menuitem',{name:'Delete',exact:true}).click();
  await expect(page.getByRole('button',{name:'Renamed conversation',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Undo',exact:true}).click();
  await page.getByRole('button',{name:'Renamed conversation',exact:true}).click();
  await expect(page.locator('.fms-assistant-message p')).toHaveText('Earlier answer');
  await expect(page.getByRole('complementary',{name:'Chat history',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Full screen',exact:true}).click();
  assert.equal(await page.locator('.fms-assistant').evaluate(el=>Math.round(el.getBoundingClientRect().width)),1440);
  await page.getByRole('button',{name:'Full screen',exact:true}).click();
  await page.getByRole('button',{name:'Close chat',exact:true}).click();
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'AI chats',exact:true}).click();
  await expect(page.getByRole('button',{name:/Current file/})).toBeVisible();
  await expect(page.locator('.fms-assistant-preview-slot .fms-assistant')).toBeVisible();
  await expect(page.locator('.fms-preview-layout>.fms-inspector')).toBeHidden();
  await expect(page.locator('.fms-preview-tools').getByRole('button',{name:'Show file information',exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Back to file information',exact:true}).click();
  await expect(page.locator('.fms-preview-layout>.fms-inspector')).toBeVisible();
  await page.locator('.fms-preview-tools').getByRole('button',{name:'More actions',exact:true}).click();
  await expect(page.getByRole('menuitem',{name:'Download',exact:true})).toBeVisible();
  await expect(page.getByRole('menuitem',{name:'File information',exact:true})).toBeVisible();
  await page.keyboard.press('Escape');
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'AI chats',exact:true}).click();
  await page.getByRole('button',{name:'Choose assistant',exact:true}).click();
  await page.getByRole('menuitemradio',{name:'Customize assistant',exact:true}).click();
  await expect(page.getByRole('dialog',{name:'Customize assistant',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Cancel',exact:true}).click();
  await page.getByRole('separator',{name:'Chat width',exact:true}).focus();
  await page.keyboard.press('ArrowLeft');
  await expect(page.getByRole('separator',{name:'Chat width',exact:true})).toHaveAttribute('aria-valuenow','404');
  assert.deepEqual(errors,[]);
  await page.close();
});


test('assistant narrow layout keeps model controls readable and composer inside the viewport',async()=>{
  const {page,errors}=await preview(390,{}, {assistant:true,files:2});
  try {
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    const panel=page.locator('.fms-assistant');
    await expect(panel).toBeVisible();
    assert.equal(Math.round((await panel.boundingBox()).width),390);
    await page.locator('.fms-assistant-model .select__trigger').click();
    await expect(page.getByRole('option',{name:/Fast model/})).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'Customize',exact:true}).click();
    await expect(page.getByRole('dialog',{name:'Customize assistant',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'shape: hexagon',exact:true}).click();
    await page.getByRole('button',{name:'Cancel',exact:true}).click();
    const composer=await page.locator('.fms-assistant-composer').boundingBox();
    assert.ok(composer.x>=0&&composer.x+composer.width<=391&&composer.y+composer.height<=900);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('assistant model text and arrow remain separate at the minimum panel width',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,longModel:true});
  try {
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await page.getByRole('separator',{name:'Chat width',exact:true}).press('Home');
    await expect(page.getByRole('separator',{name:'Chat width',exact:true})).toHaveAttribute('aria-valuenow','320');
    const values=await page.locator('.fms-assistant-model').evaluate(row=>{
      const names=['.fms-assistant-model-trigger','.fms-assistant-model-value','.fms-assistant-model-indicator','.fms-assistant-new-chat'];
      return names.map(name=>{const r=row.querySelector(name).getBoundingClientRect();return {x:r.x,right:r.right,y:r.y,bottom:r.bottom};});
    });
    assert.ok(values[1].right<=values[2].x-3);
    assert.ok(Math.abs(values[0].y-values[3].y)<1);
    assert.ok(values[2].right<values[3].x);
    const handle=page.getByRole('separator',{name:'Chat width',exact:true});
    const before=await handle.evaluate(e=>getComputedStyle(e,'::after').backgroundColor);
    await handle.hover();
    await expect.poll(()=>handle.evaluate(e=>getComputedStyle(e,'::after').backgroundColor)).not.toBe(before);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('assistant open state survives remount and file preview hides information until Back',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,files:2,organize:true,comments:true});
  try {
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await page.evaluate(()=>window.remountStudio());
    await expect(page.getByRole('complementary',{name:'AI assistant',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await expect(page.locator('.fms-assistant-preview-slot .fms-assistant')).toBeVisible();
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toBeHidden();
    const tools=page.locator('.fms-preview-tools');
    await expect(tools.getByRole('button',{name:'Show file information',exact:true})).toHaveCount(0);
    await expect(tools.getByRole('button',{name:'Share',exact:true})).toHaveCount(0);
    await tools.getByRole('button',{name:'More actions',exact:true}).click();
    for(const name of ['File information','Move','Download','Move to trash'])await expect(page.getByRole('menuitem',{name,exact:true})).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'Back to file information',exact:true}).click();
    await expect(page.locator('.fms-preview-layout>.fms-inspector')).toBeVisible();
    await expect(page.locator('.fms-assistant-preview-slot .fms-assistant')).toBeHidden();
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


test('assistant opens the requested preview and delivers one undo toast despite replayed receipts',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantActions:true,files:2});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await page.getByRole('textbox',{name:'Message the assistant',exact:true}).fill('Rename and show the file');
    await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect(page.locator('.fms-preview-dialog')).toBeVisible();
    await expect(page.getByRole('button',{name:'Undo',exact:true})).toHaveCount(1);
    await expect(page.getByText('Streamed response',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Undo',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.assistantUndone)).toEqual(['rename-one']);
    await expect(page.getByText('Action undone',{exact:true})).toBeVisible();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('assistant switches Stop to Send while typing and cancels the previous run on replacement',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantSlow:true});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    const input=page.getByRole('textbox',{name:'Message the assistant',exact:true});
    await input.fill('First question');await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect(page.getByRole('button',{name:'Stop',exact:true})).toBeEnabled();
    await input.fill('Next question');await expect(page.getByRole('button',{name:'Send',exact:true})).toBeEnabled();
    await expect(page.getByRole('button',{name:'Stop',exact:true})).toHaveCount(0);
    await input.fill('');await expect(page.getByRole('button',{name:'Stop',exact:true})).toBeEnabled();
    await page.getByRole('button',{name:'Stop',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.assistantCancelCount)).toBe(1);
    await expect(page.getByRole('button',{name:'Send',exact:true})).toBeDisabled();
    await input.fill('Another question');await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect(page.getByRole('button',{name:'Stop',exact:true})).toBeEnabled();
    await input.fill('Replacement');await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.assistantCancelCount)).toBe(2);
    await expect.poll(()=>page.evaluate(()=>window.chatInput.text)).toBe('Replacement');
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('dictation starts lazily, commits or discards speech, and releases microphone tracks',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,dictation:true});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    const input=page.getByRole('textbox',{name:'Message the assistant',exact:true});await input.fill('Existing');
    assert.equal(await page.evaluate(()=>window.dictationStarts),0);
    const bottom=page.locator('.fms-assistant-composer-bottom');const height=(await bottom.boundingBox()).height;
    await page.getByRole('button',{name:'Dictate',exact:true}).click();
    assert.equal((await bottom.boundingBox()).height,height);
    await expect(page.getByRole('button',{name:'Starting microphone',exact:true})).toHaveAttribute('data-loading','true');
    await page.evaluate(()=>window.resolveMicrophone());await expect.poll(()=>page.evaluate(()=>window.dictationStarts)).toBe(1);
    await page.evaluate(()=>window.dictationRecognition.onstart());
    await expect(page.getByRole('button',{name:'Cancel dictation',exact:true})).toBeVisible();
    await expect(page.getByRole('img',{name:'Microphone signal',exact:true})).toBeVisible();
    assert.equal((await bottom.boundingBox()).height,height);
    await page.evaluate(()=>window.dictationRecognition.onresult({results:[{0:{transcript:'dictated words'},isFinal:false}]}));
    await expect(input).toHaveValue('Existing dictated words');
    await page.getByRole('button',{name:'Finish dictation',exact:true}).click();
    await expect(input).toHaveValue('Existing dictated words');await expect(input).not.toHaveAttribute('readonly','');
    assert.equal(await page.evaluate(()=>window.dictationTracksStopped),1);
    await page.getByRole('button',{name:'Dictate',exact:true}).click();await page.evaluate(()=>window.resolveMicrophone());
    await expect.poll(()=>page.evaluate(()=>window.dictationStarts)).toBe(2);await page.evaluate(()=>window.dictationRecognition.onstart());
    await page.evaluate(()=>window.dictationRecognition.onresult({results:[{0:{transcript:'discard this'},isFinal:false}]}));
    await page.getByRole('button',{name:'Cancel dictation',exact:true}).click();
    await expect(input).toHaveValue('Existing dictated words');assert.equal(await page.evaluate(()=>window.dictationTracksStopped),2);
    await page.getByRole('button',{name:'Dictate',exact:true}).click();await page.evaluate(()=>window.rejectMicrophone());
    await expect(page.getByRole('alert')).toContainText('Allow microphone');await expect(page.getByRole('button',{name:'Dictate',exact:true})).toBeVisible();
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('assistant dropdowns dismiss outside and unsupported dictation is hidden',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,dictationUnsupported:true});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await expect(page.getByRole('button',{name:'Dictate',exact:true})).toHaveCount(0);
    const input=page.getByRole('textbox',{name:'Message the assistant',exact:true});
    await page.getByRole('button',{name:'Actions',exact:true}).click();
    await expect(page.getByRole('menuitem',{name:'Summarize file',exact:true})).toBeVisible();
    await page.getByText('Ask me anything about this space.',{exact:true}).click();await expect(page.getByRole('menuitem',{name:'Summarize file',exact:true})).toHaveCount(0);
    await page.locator('.fms-assistant-model .select__trigger').click();
    await expect(page.getByRole('listbox')).toBeVisible();await input.click();await expect(page.getByRole('listbox')).toHaveCount(0);
    await page.getByRole('button',{name:'Actions',exact:true}).click();await page.keyboard.press('Escape');
    await expect(page.getByRole('menuitem',{name:'Summarize file',exact:true})).toHaveCount(0);
    await page.locator('.fms-assistant-model .select__trigger').click();await page.keyboard.press('Escape');await expect(page.getByRole('listbox')).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('assistant fullscreen merges its header into one row on desktop and mobile',async()=>{
  for(const width of [1440,390]){
    const {page,errors}=await preview(width,{}, {assistant:true,longModel:true});
    try{
      await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
      await page.getByRole('button',{name:'Full screen',exact:true}).click();
      const layout=await page.locator('.fms-assistant-header').evaluate(header=>{const h=header.getBoundingClientRect(),a=header.querySelector('.fms-assistant-heading').getBoundingClientRect(),b=header.querySelector('.fms-assistant-model').getBoundingClientRect();return {height:h.height,first:a.y,second:b.y,right:b.right,limit:h.right};});
      assert.equal(layout.first,layout.second);assert.ok(layout.height<=57);assert.ok(layout.right<=layout.limit);
      await page.getByRole('button',{name:'Full screen',exact:true}).click();
      const height=await page.locator('.fms-assistant-header').evaluate(el=>el.getBoundingClientRect().height);assert.ok(height>80);
      assert.deepEqual(errors,[]);
    }finally{await page.close();}
  }
});


test('assistant history virtualizes cursor pages and refetches evicted payloads',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantHistoryCount:600});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
    await expect(page.getByRole('button',{name:'Saved conversation',exact:true})).toBeVisible();
    const list=page.locator('.fms-assistant-history-scroll');
    for(let i=1;i<=8;i++){
      const before=await page.evaluate(()=>window.assistantHistoryRequests.length);
      await list.evaluate(el=>el.scrollTop=el.scrollHeight);
      await expect.poll(()=>page.evaluate(()=>window.assistantHistoryRequests.length)).toBeGreaterThan(before);
    }
    assert.ok(await page.locator('.fms-assistant-history-row').count()<35);
    await list.evaluate(el=>el.scrollTop=0);
    await expect(page.getByRole('button',{name:'Saved conversation',exact:true})).toBeVisible();
    assert.ok(await page.evaluate(()=>window.assistantHistoryRequests.filter(c=>c===null).length)>=2);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('assistant virtualizes variable messages with older and newer cursor windows',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantMessageCount:600});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'Saved conversation',exact:true}).click();
    const log=page.getByRole('log',{name:'Messages',exact:true});
    await expect(log).toContainText('Message 599');
    for(let i=0;i<7;i++){
      const before=await page.evaluate(()=>window.assistantShowRequests.length);
      await log.evaluate(el=>el.scrollTop=0);
      await expect.poll(()=>page.evaluate(()=>window.assistantShowRequests.length)).toBeGreaterThan(before);
      await expect(log).toHaveAttribute('aria-busy','false');
    }
    assert.ok(await page.locator('.fms-assistant-message').count()<25);
    await log.evaluate(el=>el.scrollTop=el.scrollHeight);
    await expect.poll(()=>page.evaluate(()=>window.assistantShowRequests.some(r=>r.page?.after))).toBe(true);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('file preview preserves the exact assistant DOM, draft, scroll and data subscription',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantMessageCount:80,files:2});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'Saved conversation',exact:true}).click();
    const input=page.getByRole('textbox',{name:'Message the assistant',exact:true});await input.fill('Unsent draft');
    const log=page.getByRole('log',{name:'Messages',exact:true});await expect(log).toContainText('Message 79');
    await log.evaluate(el=>el.scrollTop=Math.max(0,el.scrollHeight-el.clientHeight-100));
    await page.locator('.fms-assistant').evaluate(el=>{window.originalAssistant=el;window.originalComposer=el.querySelector('textarea');window.originalLog=el.querySelector('[role=log]');window.originalScroll=window.originalLog.scrollTop;window.originalShowCount=window.assistantShowRequests.length;window.chatFrameChecks=[];window.checkChatFrames=true;const check=()=>{if(!window.checkChatFrames)return;window.chatFrameChecks.push({connected:el.isConnected,visible:el.getBoundingClientRect().width>0&&el.getBoundingClientRect().height>0});requestAnimationFrame(check);};requestAnimationFrame(check);});
    const geometry=await page.locator('.fms-content').boundingBox();
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await expect(page.locator('.fms-assistant-preview-slot .fms-assistant')).toBeVisible();
    assert.equal(await page.locator('.fms-assistant').evaluate(el=>el===window.originalAssistant&&el.querySelector('textarea')===window.originalComposer&&el.querySelector('[role=log]')===window.originalLog),true);
    await expect(input).toHaveValue('Unsent draft');
    await expect.poll(()=>page.evaluate(()=>Math.abs(window.originalLog.scrollTop-window.originalScroll))).toBeLessThan(2);
    assert.equal(await page.evaluate(()=>window.assistantShowRequests.length===window.originalShowCount),true);
    await page.locator('.fms-preview-back').click();
    await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
    assert.equal(await page.locator('.fms-assistant').evaluate(el=>el===window.originalAssistant&&el.querySelector('[role=log]')===window.originalLog),true);
    await expect(input).toHaveValue('Unsent draft');
    await expect.poll(()=>page.evaluate(()=>Math.abs(window.originalLog.scrollTop-window.originalScroll))).toBeLessThan(2);
    const after=await page.locator('.fms-content').boundingBox();assert.equal(Math.round(after.width),Math.round(geometry.width));
    const frames=await page.evaluate(()=>{window.checkChatFrames=false;return window.chatFrameChecks;});assert.ok(frames.length>0);assert.ok(frames.every(frame=>frame.connected&&frame.visible));
    assert.equal(await page.evaluate(()=>window.assistantShowRequests.length===window.originalShowCount),true);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('preview does not reconnect or restart an active assistant response',async()=>{
  const {page,errors}=await preview(1440,{}, {assistant:true,assistantSlow:true,files:2});
  try{
    await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
    await page.getByRole('textbox',{name:'Message the assistant',exact:true}).fill('Question');await page.getByRole('button',{name:'Send',exact:true}).click();
    await expect(page.getByRole('button',{name:'Stop',exact:true})).toBeEnabled();
    await expect.poll(()=>page.evaluate(()=>window.assistantEventSubscriptions)).toBe(1);
    await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
    await expect(page.locator('.fms-assistant-preview-slot .fms-assistant')).toBeVisible();
    await page.locator('.fms-preview-back').click();await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
    assert.equal(await page.evaluate(()=>window.assistantEventSubscriptions),1);
    assert.equal(await page.evaluate(()=>window.assistantCancelCount??0),0);
    await expect(page.getByRole('button',{name:'Stop',exact:true})).toBeEnabled();assert.deepEqual(errors,[]);
  }finally{await page.close();}
});


test('settings persist automatic/manual timezone with a virtualized grouped search',async()=>{
 const {page,errors}=await preview();try{
  await page.getByRole('button',{name:'Settings',exact:true}).click();const dialog=page.locator('.fms-settings-dialog');
  await expect(dialog.getByRole('button',{name:/Language/})).toBeVisible();
  await expect(dialog.getByRole('switch',{name:'Automatic timezone',exact:true})).toBeChecked();
  await expect(dialog.getByRole('button',{name:/Set timezone/})).toHaveCount(0);
  await dialog.locator('[data-slot=switch-content]').filter({has:page.getByRole('switch',{name:'Automatic timezone',exact:true})}).click();
  await dialog.locator('.fms-timezone-select [data-slot=autocomplete-trigger]').click();
  await expect(page.getByRole('searchbox',{name:'Search timezones',exact:true})).toBeVisible();
  assert.ok(await page.getByRole('option').count()<60);
  await page.getByRole('searchbox',{name:'Search timezones',exact:true}).fill('Warsaw');
  await page.getByRole('option',{name:'Europe / Warsaw',exact:true}).click();
  await expect(dialog.locator('.fms-timezone-select')).toContainText('Europe / Warsaw');
  const saved=await page.evaluate(()=>JSON.parse(localStorage.getItem('flex-media-studio.preferences.browser')));assert.equal(saved.autoTimezone,false);assert.equal(saved.timezone,'Europe/Warsaw');
  await dialog.locator('[data-slot=switch-content]').filter({has:page.getByRole('switch',{name:'Automatic timezone',exact:true})}).click();await expect(dialog.locator('.fms-timezone-select')).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('account menu owns workspace switching and single-workspace mode hides all workspace controls',async()=>{
 for(const singleWorkspace of [false,true]){
  const {page,errors}=await preview(1440,{}, {singleWorkspace});try{
   await expect(page.getByRole('button',{name:'Workspaces',exact:true})).toHaveCount(0);
   await expect(page.locator('.fms-sidebar').getByText('Workspaces',{exact:true})).toHaveCount(0);
   await page.getByRole('button',{name:'Account menu',exact:true}).click();
   if(singleWorkspace){await expect(page.getByRole('menuitem',{name:'Campaign',exact:true})).toHaveCount(0);await expect(page.getByRole('menuitem',{name:'New workspace',exact:true})).toHaveCount(0);}
   else{await page.getByRole('menuitem',{name:'Campaign',exact:true}).click();await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).workspace)).toBe('two');}
   assert.deepEqual(errors,[]);
  }finally{await page.close();}
 }
});

test('subnav resizes without collapsing or changing the primary icon rail',async()=>{
 const {page,errors}=await preview();try{
  const rail=await page.locator('.fms-rail').boundingBox();await expect(page.getByRole('button',{name:'Collapse navigation',exact:true})).toHaveCount(0);
  await page.getByRole('separator',{name:'Navigation width',exact:true}).focus();await page.keyboard.press('ArrowRight');
  await expect.poll(async()=>Math.round((await page.locator('.fms-sidebar').boundingBox()).width)).toBe(268);
  const after=await page.locator('.fms-rail').boundingBox();assert.equal(after.width,rail.width);assert.equal(after.x,rail.x);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('chat history closes when clicking outside its drawer',async()=>{
 const {page,errors}=await preview(1440,{}, {assistant:true});try{
  await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await expect(page.getByRole('complementary',{name:'Chat history',exact:true})).toBeVisible();
  await page.locator('.fms-title-row h1').click();await expect(page.getByRole('complementary',{name:'Chat history',exact:true})).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('compact account appearance follows the system and settings overlay a docked chat',async()=>{
 const {page,errors}=await preview(1440,{user:{name:'Jane Doe',email:'jane@example.test'}},{assistant:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();
  await expect(page.getByRole('tab',{name:'System',exact:true})).toBeVisible();
  const account=await page.locator('.fms-account-popover').boundingBox();assert.ok(account.width<=260);
  await page.getByRole('tab',{name:'System',exact:true}).click();await page.emulateMedia({colorScheme:'dark'});await expect(page.locator('.fms-root')).toHaveClass(/dark/);
  await page.emulateMedia({colorScheme:'light'});await expect(page.locator('.fms-root')).toHaveClass(/light/);
  await page.keyboard.press('Escape');
  const search=await page.locator('.fms-header-search [data-slot="search-field-group"]').evaluate(el=>el.offsetHeight),avatar=await page.locator('.fms-account-trigger').evaluate(el=>el.offsetHeight);assert.equal(search,avatar);
  await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
  await page.locator('.fms-rail').getByRole('button',{name:'Settings',exact:true}).click();
  const overlay=page.locator('.fms-settings-backdrop');await expect(overlay).toBeVisible();
  const cover=await overlay.evaluate(el=>{const r=el.getBoundingClientRect(),chat=el.getRootNode().querySelector('.fms-assistant').getBoundingClientRect();return {right:r.right,chatRight:chat.right,z:Number(getComputedStyle(el).zIndex)};});assert.ok(cover.right>=cover.chatRight);assert.ok(cover.z>60);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('configured primary color reaches controls and profile appearance row is aligned',async()=>{
 const {page,errors}=await preview(1440,{primaryColor:'#b51b43',user:{name:'Jane Doe',email:'jane@example.test'}});try{
  await expect(page.locator('.fms-root')).toHaveCSS('--accent','#b51b43');
  await page.getByRole('button',{name:'Account menu',exact:true}).click();
  await expect(page.locator('.fms-account-identity').getByText('Jane Doe',{exact:true})).toBeVisible();await expect(page.locator('.fms-account-identity').getByText('jane@example.test',{exact:true})).toBeVisible();
  await expect(page.locator('.fms-account-identity .avatar')).toBeVisible();
  const row=await page.locator('.fms-account-appearance').evaluate(el=>{const text=el.querySelector('span').getBoundingClientRect(),tabs=el.querySelector('.tabs').getBoundingClientRect();return {text:text.right,tabs:tabs.left,delta:Math.abs((text.top+text.height/2)-(tabs.top+tabs.height/2))};});assert.ok(row.tabs>row.text&&row.delta<2,JSON.stringify(row));
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('native directory supplies primary color and viewer without a React profile override',async()=>{
 const {page,errors}=await preview(1440,{}, {brandingMetadata:true});try{
  await expect(page.locator('.fms-root')).toHaveCSS('--accent','#17aa55');await page.getByRole('button',{name:'Account menu',exact:true}).click();await expect(page.locator('.fms-account-identity').getByText('Server user',{exact:true})).toBeVisible();await expect(page.locator('.fms-account-identity').getByText('server@example.test',{exact:true})).toBeVisible();assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('workspace settings uses a separate confirmed move-or-delete flow and keeps progress',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceSettings:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Workspaces · Settings',exact:true}).click();
  const dialog=page.getByRole('dialog',{name:'Workspace settings',exact:true});await expect(dialog.getByLabel('Workspace name',{exact:true})).toHaveValue('Brand assets');
  await dialog.getByRole('button',{name:'Remove…',exact:true}).click();await expect(dialog.getByRole('radio',{name:'Move files to another workspace',exact:true})).toBeChecked();
  await expect(dialog.getByRole('button',{name:'Remove workspace',exact:true})).toBeDisabled();await dialog.getByLabel('Type the workspace name to confirm',{exact:true}).fill('Brand assets');await dialog.locator('[data-slot="checkbox-content"]').click();
  await dialog.getByRole('button',{name:'Remove workspace',exact:true}).click();await expect(dialog.getByText('Items processed: 0',{exact:true})).toBeVisible();
  assert.deepEqual(await page.evaluate(()=>window.workspaceRetirementInput),{id:'one',confirmName:'Brand assets',mode:'move',target:'two'});assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for (const width of [390,1440]) test(`workspace wizard preserves choices, lazily picks emoji and submits once at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {workspaceIdentity:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();
  const dialog=page.getByRole('dialog',{name:'Create workspace',exact:true});await expect(dialog).toBeVisible();
  assert.equal(await page.evaluate(()=>performance.getEntriesByType('resource').some(e=>e.name.endsWith('/workspace-emoji.js'))),false);
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('textbox',{name:'Workspace name',exact:true}).fill('Creative studio');
  await dialog.getByRole('button',{name:'Choose workspace icon'}).click();
  const picker=page.getByRole('dialog',{name:'Workspace icon',exact:true});await expect(picker).toBeVisible();
  await picker.getByRole('textbox').fill('rocket');
  await picker.getByRole('gridcell',{name:'rocket',exact:true}).first().click();
  await expect(picker).toHaveCount(0);
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('button',{name:'Back',exact:true}).click();await expect(dialog.getByRole('textbox',{name:'Workspace name',exact:true})).toHaveValue('Creative studio');
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.workspaceCreates??0),0);
  const bounds=await dialog.boundingBox();assert.ok(bounds.width>=width-2);assert.ok(bounds.x>=0);
  await dialog.getByRole('button',{name:'Create workspace',exact:true}).click();await expect(dialog).toHaveCount(0);
  const body=await page.evaluate(()=>window.createdWorkspaceInput);assert.equal(body.emoji,'🚀');assert.equal(body.kind,'personal');assert.ok(body.requestKey);assert.equal(await page.evaluate(()=>window.workspaceCreates),1);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('team wizard sends invitations explicitly and retries only failed recipients',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceIdentity:true,workspaceInvites:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();
  const dialog=page.getByRole('dialog',{name:'Create workspace',exact:true});
  await dialog.getByRole('button',{name:/^Team Shared/}).click();await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  await dialog.getByRole('textbox',{name:'Workspace name',exact:true}).fill('Team launch');await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  await dialog.getByRole('textbox',{name:'Email addresses (optional)'}).fill('first@example.com, second@example.com');
  await dialog.getByRole('button',{name:'Continue',exact:true}).click();await expect(dialog.getByRole('heading',{name:'Your privacy comes first.',exact:true})).toBeVisible();assert.equal(await dialog.getByRole('textbox',{name:'Email addresses (optional)'}).count(),0);await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('button',{name:'Create workspace',exact:true}).click();
  await expect(dialog.getByRole('button',{name:'Send invitations (2)'})).toBeVisible();assert.equal(await page.evaluate(()=>window.invitationAttempts?.length??0),0);
  await dialog.getByRole('button',{name:'Send invitations (2)'}).click();await expect(dialog.getByRole('alert')).toHaveText('Temporary mail failure');
  await dialog.getByRole('button',{name:'Send invitations (1)'}).click();await expect(dialog.getByRole('button',{name:'Invitations sent'})).toBeDisabled();
  assert.deepEqual(await page.evaluate(()=>window.invitationAttempts.map(v=>v.email)),['first@example.com','second@example.com','second@example.com']);
  assert.equal(await page.evaluate(()=>window.workspaceCreates),1);assert.equal(await page.evaluate(()=>window.createdWorkspaceInput.kind),'team');assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('workspace emoji can be changed in settings and cleared to the default folder',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceIdentity:true,workspaceSettings:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Workspaces · Settings',exact:true}).click();
  let dialog=page.getByRole('dialog',{name:'Workspace settings',exact:true});await dialog.getByRole('button',{name:'Choose workspace icon'}).click();
  const picker=page.getByRole('dialog',{name:'Workspace icon',exact:true});await picker.getByRole('textbox').fill('rocket');await picker.getByRole('gridcell',{name:'rocket',exact:true}).click();
  await dialog.getByRole('button',{name:'Save changes',exact:true}).click();await expect(dialog).toHaveCount(0);assert.equal(await page.evaluate(()=>window.savedWorkspaceIdentity.emoji),'🚀');
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Workspaces · Settings',exact:true}).click();
  dialog=page.getByRole('dialog',{name:'Workspace settings',exact:true});await dialog.getByRole('button',{name:'Choose workspace icon'}).click();await picker.getByRole('button',{name:'Default folder icon'}).click();await dialog.getByRole('button',{name:'Save changes',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.savedWorkspaceIdentity.emoji),null);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const language of ['de-DE','fr-CA','es-MX','ja-JP','zh-TW','xx-ZZ'])test(`emoji datasets are lazy and resolve ${language} with EN fallback`,async()=>{
 const {page,errors}=await preview(1440,{locale:language},{workspaceIdentity:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();
  const requests=()=>page.evaluate(()=>performance.getEntriesByType('resource').map(e=>new URL(e.name).pathname));
  const before=await requests();assert.ok(before.includes('/studio-locale-en.js'));assert.ok(!before.some(p=>p.startsWith('/emoji-data-')));assert.ok(!before.includes('/studio-locale-pl.js'));
  await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByRole('button',{name:'Choose workspace icon'}).click();
  const picker=page.getByRole('dialog',{name:'Workspace icon',exact:true});await expect(picker.getByRole('gridcell').first()).toBeVisible();
  const expected={'de-DE':'de','fr-CA':'fr','es-MX':'es-mx','ja-JP':'ja','zh-TW':'zh-hant','xx-ZZ':'en'}[language];
  const files=(await requests()).filter(p=>p.startsWith('/emoji-data-'));assert.deepEqual(files,['/emoji-data-'+expected+'.js']);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('catalog providers commit a locale atomically and cache it across a repeated switch',async()=>{
 const {page,errors}=await preview(1440,{locale:'en'},{workspaceIdentity:true});try{
  await page.evaluate(()=>{window.localeLoaderCalls=0;window.localeLoader=async()=>{window.localeLoaderCalls++;await new Promise(resolve=>window.releaseLocale=resolve);return {messages:{newWorkspace:'Neuer Arbeitsbereich'},workspace:{createWorkspace:'Arbeitsbereich erstellen',newWorkspace:'Neuer Arbeitsbereich',titles0:'Ein Platz für Ideen.',continue:'Weiter'}};};window.fixtureOptions={...window.fixtureOptions,locale:'de-DE',localization:{catalogs:{de:{label:'Deutsch',load:window.localeLoader}}}};window.remountStudio();});
  await expect.poll(()=>page.evaluate(()=>!!window.releaseLocale)).toBe(true);
  await expect(page.getByRole('button',{name:'Account menu',exact:true})).toHaveCount(0);
  await page.evaluate(()=>window.releaseLocale());await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Neuer Arbeitsbereich',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Ein Platz für Ideen.'})).toBeVisible();await expect(page.getByRole('button',{name:'Weiter',exact:true})).toBeVisible();
  await page.evaluate(()=>window.remountStudio());await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Neuer Arbeitsbereich',exact:true}).click();assert.equal(await page.evaluate(()=>window.localeLoaderCalls),1);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('failed optional locale loads use EN and a later mount can retry the loader',async()=>{
 const {page,errors}=await preview(1440,{locale:'en'},{workspaceIdentity:true});try{
  await page.evaluate(()=>{window.localeLoaderCalls=0;window.localeLoader=async()=>{window.localeLoaderCalls++;if(window.localeLoaderCalls===1)throw new Error('Catalog offline');return {workspace:{titles0:'Catalogue recovered'}};};window.fixtureOptions={...window.fixtureOptions,locale:'fr',localization:{catalogs:{fr:{load:window.localeLoader}}}};window.remountStudio();});
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await expect(page.getByRole('heading',{name:'A home for your ideas.'})).toBeVisible();
  await page.evaluate(()=>window.remountStudio());await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await expect(page.getByRole('heading',{name:'Catalogue recovered'})).toBeVisible();assert.equal(await page.evaluate(()=>window.localeLoaderCalls),2);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('adding twelve lazy interface catalogs only calls the selected loader',async()=>{
 const {page,errors}=await preview(1440,{locale:'en'},{workspaceIdentity:true});try{
  await page.evaluate(()=>{window.loadedLanguages=[];const ids=['de','fr','es','it','pt','nl','sv','da','fi','ja','ko','uk'];window.fixtureOptions={...window.fixtureOptions,locale:'it-IT',localization:{catalogs:Object.fromEntries(ids.map(id=>[id,{load:async()=>{window.loadedLanguages.push(id);return {workspace:{titles0:'Locale '+id}}}}]))}};window.remountStudio();});
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await expect(page.getByRole('heading',{name:'Locale it'})).toBeVisible();assert.deepEqual(await page.evaluate(()=>window.loadedLanguages),['it']);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('a failed emoji dataset falls back to the separate English asset',async()=>{
 const {page,errors}=await preview(1440,{locale:'de-DE'},{workspaceIdentity:true});try{
  await page.route('**/emoji-data-de.js',route=>route.fulfill({status:503,contentType:'text/javascript',body:'unavailable'}));
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByRole('button',{name:'Choose workspace icon'}).click();
  const picker=page.getByRole('dialog',{name:'Workspace icon',exact:true});await expect(picker.getByRole('gridcell').first()).toBeVisible();await picker.getByRole('textbox').fill('rocket');await expect(picker.getByRole('gridcell',{name:'rocket',exact:true})).toBeVisible();assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('locale switching keeps the live app mounted and commits a complete catalog',async()=>{
 const {page,errors}=await preview(1440,{locale:'en'},{workspaceIdentity:true});try{
  await page.evaluate(()=>{window.fixtureOptions={...window.fixtureOptions,localization:{catalogs:{de:{label:'Deutsch',load:()=>new Promise(resolve=>{window.releaseGerman=()=>resolve({messages:{language:'Sprache'},workspace:{titles0:'Ein Platz für Ideen.'}});})}}}};window.remountStudio();});
  await page.getByRole('button',{name:'Settings',exact:true}).click();const dialog=page.locator('.fms-settings-dialog');await dialog.getByRole('button',{name:/Language/}).click();await page.getByRole('option',{name:'Deutsch',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>!!window.releaseGerman)).toBe(true);await expect(dialog.getByRole('button',{name:/Language/})).toBeVisible();const before=await page.evaluate(()=>window.workspaceRequests.length);await page.evaluate(()=>window.releaseGerman());await expect(dialog.getByRole('button',{name:/Sprache/})).toBeVisible();assert.equal(await page.evaluate(()=>window.workspaceRequests.length),before);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('emoji search and skin tones use HeroUI controls without closing the picker',async()=>{
 const {page,errors}=await preview(390,{}, {workspaceIdentity:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).click();
  const wizard=page.getByRole('dialog',{name:'Create workspace',exact:true});assert.equal(await wizard.locator('.fms-workspace-art').count(),0);
  await wizard.getByRole('button',{name:'Choose workspace icon'}).click();const picker=page.getByRole('dialog',{name:'Workspace icon',exact:true});
  await picker.getByRole('button',{name:/^Skin tone:/}).click();const menu=page.getByRole('menu',{name:/^Skin tone/});await expect(menu.getByRole('menuitemradio')).toHaveCount(6);
  const geometry=await menu.getByRole('menuitemradio',{name:'Default skin tone',exact:true}).evaluate(row=>{const swatch=row.querySelector('.fms-emoji-tone-swatch').getBoundingClientRect(),label=row.querySelector('.label').getBoundingClientRect(),check=row.querySelector('[data-slot=menu-item-indicator]').getBoundingClientRect();return {swatchRight:swatch.right,labelLeft:label.left,labelRight:label.right,checkLeft:check.left};});assert.ok(geometry.swatchRight<geometry.labelLeft&&geometry.labelRight<=geometry.checkLeft,'checkmark must follow label at the right edge');
  await menu.getByRole('menuitemradio',{name:'Medium skin tone',exact:true}).click();await expect(menu).toHaveCount(0);await expect(picker).toBeVisible();await expect(picker.getByRole('button',{name:'Skin tone: Medium skin tone',exact:true})).toBeVisible();
  const search=picker.getByRole('textbox');await search.fill('rocket');await expect(picker.getByRole('gridcell',{name:'rocket',exact:true})).toBeVisible();await picker.getByRole('button',{name:'Clear search',exact:true}).click();await expect(search).toHaveValue('');
  await picker.getByRole('button',{name:/^Skin tone:/}).click();await page.keyboard.press('Escape');await expect(menu).toHaveCount(0);await expect(picker).toBeVisible();
  const bounds=await picker.boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=390);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`workspace names require 3–15 characters at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {workspaceIdentity:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).click();
  const wizard=page.getByRole('dialog',{name:'Create workspace',exact:true}),name=wizard.getByRole('textbox',{name:'Workspace name',exact:true}),next=wizard.getByRole('button',{name:'Continue',exact:true});
  const iconBounds=await wizard.getByRole('button',{name:'Choose workspace icon',exact:true}).boundingBox(),inputBounds=await name.boundingBox();assert.ok(iconBounds.x+iconBounds.width<inputBounds.x&&Math.abs(iconBounds.y-inputBounds.y)<2,'name input stays beside icon');
  await name.fill('ab');await expect(next).toBeDisabled();await name.press('Enter');await expect(name).toBeVisible();
  await name.fill('abc');await expect(next).toBeEnabled();await name.fill('  ab  ');await expect(next).toBeDisabled();
  await name.fill('1234567890123456');await expect(name).toHaveValue('123456789012345');await expect(next).toBeEnabled();await next.click();await expect(wizard.getByRole('heading',{name:'Your privacy comes first.',exact:true})).toBeVisible();assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('workspace creation keeps the footer stable and waits for server readiness',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceIdentity:true,deferWorkspaceCreate:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Create workspace',exact:true});await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('textbox',{name:'Workspace name',exact:true}).fill('Studio');await dialog.getByRole('button',{name:'Continue',exact:true}).click();await expect(dialog.getByRole('heading',{name:'Your privacy comes first.',exact:true})).toBeVisible();await dialog.getByRole('button',{name:'Continue',exact:true}).click();
  const create=dialog.locator('.fms-create-submit'),before=await create.boundingBox();await create.click();await expect(create).toBeDisabled();await expect(dialog.locator('.fms-create-footer')).toHaveAttribute('aria-busy','true');await expect(dialog).toBeVisible();await expect(dialog.getByRole('button',{name:'Back',exact:true})).toBeDisabled();const after=await create.boundingBox();assert.equal(before.width,after.width);assert.equal(before.height,after.height);assert.equal(await page.evaluate(()=>window.workspaceCreates),1);
  await page.evaluate(()=>window.resolveWorkspaceCreate());await expect(dialog).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('team picker searches after three characters and adds existing users without mail',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceIdentity:true,workspacePeople:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'New workspace',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Create workspace',exact:true});await dialog.getByRole('button',{name:/^Team Shared/}).click();await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('textbox',{name:'Workspace name',exact:true}).fill('Team demo');await dialog.getByRole('button',{name:'Continue',exact:true}).click();await expect(dialog.getByRole('heading',{name:'Make room for your team.',exact:true})).toBeVisible();assert.equal(await dialog.locator('.fms-create-lock-art').count(),0);
  const peopleTrigger=dialog.locator('[data-slot=autocomplete-trigger]');const emptyHeight=(await peopleTrigger.boundingBox()).height;await peopleTrigger.click();const search=page.getByRole('searchbox',{name:'Search users…',exact:true});await search.fill('Te');await expect(page.getByText('Enter at least 3 characters.',{exact:true})).toBeVisible();assert.equal(await page.evaluate(()=>window.peopleSearches?.length??0),0);await search.fill('Tea');await expect(page.getByRole('option')).toHaveCount(15);await page.getByRole('option',{name:/Teammate 1 team1/}).click();await search.press('Escape');await search.press('Escape');await expect(search).toBeHidden();assert.equal((await peopleTrigger.boundingBox()).height,emptyHeight);const chip=dialog.locator('[data-slot=tag]');await expect(chip).toHaveCount(1);const chipStyle=await chip.evaluate(el=>{const avatar=el.querySelector('.avatar'),remove=el.querySelector('[data-slot=tag-remove-button]');return {background:getComputedStyle(el).backgroundColor,avatar:getComputedStyle(avatar).backgroundColor,remove:getComputedStyle(remove).backgroundColor,radius:getComputedStyle(remove).borderRadius};});assert.notEqual(chipStyle.background,chipStyle.remove);assert.notEqual(chipStyle.avatar,'rgba(0, 0, 0, 0)');assert.equal(chipStyle.radius,'50%');await peopleTrigger.click();await search.fill('Tea');for(const n of [2,3,4,5])await page.getByRole('option',{name:new RegExp('Teammate '+n+' team'+n)}).click();await search.press('Escape');await search.press('Escape');await expect(search).toBeHidden();assert.ok((await peopleTrigger.boundingBox()).height>emptyHeight);for(const n of [2,3,4,5])await dialog.getByRole('button',{name:'Remove tag Teammate '+n,exact:true}).click();assert.equal((await peopleTrigger.boundingBox()).height,emptyHeight);await dialog.getByRole('group',{name:'Role for Teammate 1',exact:true}).getByRole('button').click();await page.getByRole('option',{name:/^Viewer/}).click();await expect(chip.locator('.fms-person-role')).toHaveText('Viewer');await dialog.getByRole('button',{name:'Continue',exact:true}).click();await expect(dialog.getByRole('heading',{name:'Your privacy comes first.',exact:true})).toBeVisible();assert.equal(await dialog.locator('.fms-workspace-people').count(),0);await dialog.getByRole('button',{name:'Continue',exact:true}).click();await dialog.getByRole('button',{name:'Create workspace',exact:true}).click();await expect(dialog).toHaveCount(0);assert.equal(await page.evaluate(()=>window.peopleAssignments.length),1);assert.equal(await page.evaluate(()=>window.peopleAssignments[0].role),'viewer');assert.equal(await page.evaluate(()=>window.invitationAttempts?.length??0),0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`workspace settings tabs edit header icon and manage access at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {workspaceIdentity:true,workspaceSettings:true,workspaceAccess:true});try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('menuitem',{name:'Workspaces · Settings',exact:true}).click();const dialog=page.getByRole('dialog',{name:'Workspace settings',exact:true});
  await expect(dialog.getByRole('tab',{name:'General',exact:true})).toHaveAttribute('aria-selected','true');assert.equal(await dialog.locator('.fms-workspace-identity-edit').count(),0);await expect(dialog.locator('.fms-workspace-head').getByRole('button',{name:'Choose workspace icon',exact:true})).toBeVisible();assert.equal(await page.evaluate(()=>window.settingsMemberLoads??0),1);
  await dialog.getByRole('textbox',{name:'Workspace name',exact:true}).fill('New name');await dialog.getByRole('tab',{name:'People & access',exact:true}).click();await expect(dialog.getByRole('row').filter({hasText:'Ada Nowak'})).toBeVisible();await expect(dialog.getByText('Ada Nowak',{exact:true})).toBeVisible();await expect(dialog.getByRole('heading',{name:'Team invitations',exact:true})).toBeVisible();await expect(dialog.getByText('invited@example.test',{exact:true})).toBeVisible();assert.equal(await dialog.locator('details.fms-workspace-invitations').count(),0);
  const row=dialog.getByRole('row').filter({hasText:'Ada Nowak'});await row.getByRole('button',{name:/Access level/}).click();await page.getByRole('option',{name:/^Viewer/}).click();await expect(row.getByRole('button',{name:/Viewer Access level/})).toBeVisible();assert.deepEqual(await page.evaluate(()=>window.settingsRoleChange),{workspace:'one',id:'ada',role:'viewer'});
  await row.getByRole('button',{name:'Remove access',exact:true}).click();await dialog.getByRole('group',{name:'Remove access',exact:true}).getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(await page.evaluate(()=>window.settingsRemoved??null),null);
  await dialog.getByRole('button',{name:'Add people',exact:true}).click();const addDialog=page.getByRole('dialog',{name:'Add people',exact:true});await addDialog.locator('[data-slot=autocomplete-trigger]').click();const search=page.getByRole('searchbox',{name:'Search users…',exact:true});await search.fill('Anna');await page.getByRole('option',{name:/Anna Kowalska/}).click();await search.press('Escape');await search.press('Escape');await expect(search).toBeHidden();await expect(addDialog.locator('[data-slot=tag] .fms-person-role')).toHaveText('Member');await addDialog.getByRole('button',{name:'Add selected',exact:true}).click();await expect(addDialog.getByRole('status')).toHaveText('Access updated.');await addDialog.getByRole('button',{name:'Close',exact:true}).last().click();await expect(dialog.getByRole('row').filter({hasText:'Anna Kowalska'}).getByText('Anna Kowalska',{exact:true})).toBeVisible();assert.equal(await page.evaluate(()=>window.settingsAdded.role),'editor');
  await dialog.getByRole('tab',{name:'General',exact:true}).click();await expect(dialog.getByRole('textbox',{name:'Workspace name',exact:true})).toHaveValue('New name');const tabs=await dialog.locator('[data-slot=tabs-list]').boundingBox(),body=await dialog.boundingBox();assert.ok(tabs.width<body.width-50);for(const label of ['General','People & access'])assert.equal(await dialog.getByRole('tab',{name:label,exact:true}).evaluate(el=>getComputedStyle(el).whiteSpace),'nowrap');assert.equal(await dialog.locator('.fms-workspace-tab-selection').count(),0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('image editor uses a gliding neutral dock, right labels and persistent anchored comments',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,drawing:true,comments:true,brandingMetadata:true});await page.route('**/original/000',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#eef2f5"/></svg>'}));try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();await page.getByRole('button',{name:'Draw on the image',exact:true}).click();const toolbar=page.locator('.fms-drawing-tools-main');const pen=toolbar.getByRole('button',{name:'Pen',exact:true});
  await expect(toolbar.getByRole('button',{name:'Add a comment',exact:true})).toBeVisible();assert.equal(await toolbar.evaluate(el=>getComputedStyle(el).borderRadius),'20px');await toolbar.getByRole('button',{name:'Text',exact:true}).click();const text=toolbar.getByRole('button',{name:'Text',exact:true});await expect(text).toHaveAttribute('aria-pressed','true');await expect.poll(async()=>{const a=await text.boundingBox(),b=await page.locator('.fms-drawing-selection-tint').boundingBox();return Math.abs(a.y-b.y);}).toBeLessThan(1);
  await page.mouse.move(0,0);await text.hover();const tip=page.getByRole('tooltip',{name:'Text',exact:true});await expect(tip).toBeVisible();assert.ok((await tip.boundingBox()).x>=(await text.boundingBox()).x+(await text.boundingBox()).width-1);
  await toolbar.getByRole('button',{name:'Add a comment',exact:true}).click();const surface=page.locator('.fms-drawing-surface');const box=await surface.boundingBox();await page.mouse.click(box.x+box.width*.6,box.y+box.height*.3);const composer=page.getByRole('form',{name:'Add a comment',exact:true});await expect(composer).toBeVisible();await expect(composer.getByText('Server user',{exact:true})).toBeVisible();await composer.getByRole('textbox',{name:'Add a comment',exact:true}).fill('Check the lighting here');await composer.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(1);const anchor=await page.evaluate(()=>window.commentItems[0].anchor);assert.ok(Math.abs(anchor.x-600)<=2);assert.ok(Math.abs(anchor.y-300)<=2);
  await page.locator('.fms-drawing-footer').getByRole('button',{name:'Cancel',exact:true}).click();await page.getByRole('button',{name:'Draw on the image',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(1);await page.locator('.fms-drawing-comment-pin').click();await page.getByRole('form',{name:'Comments',exact:true}).getByRole('button',{name:'Resolve',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(0);assert.equal(await page.evaluate(()=>window.commentItems[0].resolved),true);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('portrait image comment composer stays readable beside the dock and inside the preview stage',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,drawing:true,comments:true});await page.route('**/original/000',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="960"><rect width="320" height="960" fill="#eef2f5"/></svg>'}));try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();await page.getByRole('button',{name:'Draw on the image',exact:true}).click();await page.getByRole('button',{name:'Add a comment',exact:true}).click();const surface=await page.locator('.fms-drawing-surface').boundingBox();await page.mouse.click(surface.x+surface.width*.7,surface.y+surface.height*.3);const form=page.getByRole('form',{name:'Add a comment',exact:true});await expect(form).toBeVisible();const stage=await page.locator('.fms-preview-stage').boundingBox(),box=await form.boundingBox();assert.ok(box.x>=stage.x&&box.x+box.width<=stage.x+stage.width);assert.ok(box.width>=250);await expect(form.locator('.avatar')).toBeVisible();assert.equal(await page.locator('.fms-drawing-history').count(),0);assert.equal(await form.locator('.avatar').evaluate(el=>{const r=el.getBoundingClientRect(),root=el.getRootNode();return root.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.closest('.fms-drawing-comment-card')===el.closest('.fms-drawing-comment-card');}),true);await form.getByRole('textbox',{name:'Add a comment',exact:true}).fill('Portrait review');await form.getByRole('button',{name:'Send',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(1);await page.locator('.fms-drawing-comment-pin').click();const saved=page.getByRole('form',{name:'Comments',exact:true});await saved.getByRole('button',{name:'Delete',exact:true}).click();await expect(saved.getByText('This comment will be permanently deleted.',{exact:true})).toBeVisible();await saved.getByRole('button',{name:'Cancel',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(1);await saved.getByRole('button',{name:'Delete',exact:true}).click();await saved.getByRole('button',{name:'Delete',exact:true}).click();await expect(page.locator('.fms-drawing-comment-pin')).toHaveCount(0);assert.equal(await page.evaluate(()=>window.commentItems.length),0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('drawing shapes share one dock tile with a right tray, remembered choice and keyboard dismissal',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,drawing:true});await page.route('**/original/000',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700"><rect width="1000" height="700" fill="#eef2f5"/></svg>'}));try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();await page.getByRole('button',{name:'Draw on the image',exact:true}).click();const dock=page.locator('.fms-drawing-tools-main');await expect(dock.locator('[data-dock-main]')).toHaveCount(5);const group=page.getByRole('button',{name:'Shapes: Rectangle',exact:true});const centered=await group.evaluate(el=>{const a=el.getBoundingClientRect(),b=el.querySelector('svg').getBoundingClientRect();return Math.max(Math.abs(a.x+a.width/2-b.x-b.width/2),Math.abs(a.y+a.height/2-b.y-b.height/2));});assert.ok(centered<.5);await group.click();const tray=page.getByRole('toolbar',{name:'Shapes',exact:true});await expect(tray.getByRole('button')).toHaveCount(4);await expect(tray).toHaveAttribute('aria-orientation','vertical');assert.equal(await page.locator('.fms-drawing-dock-surface').count(),1);assert.equal(await page.locator('.fms-drawing-history').count(),0);await expect.poll(async()=>await page.locator('.fms-drawing-dock-surface path').getAttribute('d')).toContain('-48');const trayBox=await tray.boundingBox();assert.ok(trayBox.height>trayBox.width*3);const offsets=await tray.locator('button').evaluateAll(items=>items.map(el=>{const a=el.getBoundingClientRect(),b=el.querySelector('svg').getBoundingClientRect();return Math.max(Math.abs(a.x+a.width/2-b.x-b.width/2),Math.abs(a.y+a.height/2-b.y-b.height/2));}));assert.ok(offsets.every(offset=>offset<.5));await expect.poll(async()=>{const a=await group.boundingBox(),b=await tray.boundingBox();return b.x-(a.x+a.width);}).toBeGreaterThanOrEqual(-1);await tray.getByRole('button',{name:'Ellipse',exact:true}).click();await expect(page.locator('.fms-drawing-surface')).toHaveAttribute('data-tool','ellipse');const remembered=page.getByRole('button',{name:'Shapes: Ellipse',exact:true});await expect(remembered).toHaveAttribute('aria-expanded','false');await remembered.press('ArrowRight');await expect(tray.getByRole('button',{name:'Ellipse',exact:true})).toBeFocused();await tray.getByRole('button',{name:'Ellipse',exact:true}).press('ArrowRight');await tray.getByRole('button',{name:'Arrow',exact:true}).press('Enter');const arrow=page.getByRole('button',{name:'Shapes: Arrow',exact:true});await expect(arrow).toBeFocused();await expect(page.locator('.fms-drawing-surface')).toHaveAttribute('data-tool','arrow');await arrow.click();await expect(tray).toBeVisible();await arrow.press('Escape');await expect(arrow).toHaveAttribute('aria-expanded','false');await expect(page.locator('.fms-drawing-surface')).toHaveAttribute('data-tool','arrow');await arrow.click();await page.getByRole('button',{name:'Styles',exact:true}).click();await expect(arrow).toHaveAttribute('aria-expanded','false');assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('drawing dock keeps its position while comment permission loads and disables colors in comment mode',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,drawing:true,comments:true,delayedDrawingComments:true});await page.route('**/original/000',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700"><rect width="1000" height="700" fill="#eef2f5"/></svg>'}));try{
 await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();await page.getByRole('button',{name:'Draw on the image',exact:true}).click();const dock=page.locator('.fms-drawing-tools-main'),comment=page.getByRole('button',{name:'Add a comment',exact:true});await expect(comment).toBeDisabled();await expect.poll(()=>dock.evaluate(el=>getComputedStyle(el.parentElement).transform)).toBe('matrix(1, 0, 0, 1, 0, 0)');const before=await dock.boundingBox();await expect.poll(()=>page.evaluate(()=>typeof window.releaseDrawingComments)).toBe('function');await page.evaluate(()=>window.releaseDrawingComments());await expect(comment).toBeEnabled();const after=await dock.boundingBox();assert.ok(Math.abs(before.y-after.y)<.5);assert.equal(before.height,after.height);await page.getByRole('button',{name:'Styles',exact:true}).click();await expect(page.locator('.fms-drawing-styles')).toBeVisible();await comment.click();await expect(page.getByRole('button',{name:'Styles',exact:true})).toBeDisabled();await expect(page.locator('.fms-drawing-styles')).toHaveCount(0);await page.getByRole('button',{name:'Pen',exact:true}).click();await expect(page.getByRole('button',{name:'Styles',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Styles',exact:true}).click();await page.getByRole('button',{name:'Select',exact:true}).click();await expect(page.getByRole('button',{name:'Styles',exact:true})).toBeDisabled();await expect(page.locator('.fms-drawing-styles')).toHaveCount(0);assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('image preview exposes decoded scale, actual size, fit and draggable zoom without replacing the image',async()=>{
 const {page,errors}=await preview(1440,{}, {files:2,assistant:true,comments:true});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="2400" height="1600"><rect width="2400" height="1600" fill="#e8edef"/></svg>'}));
 try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  const group=page.locator('.fms-document-header-zoom');
  await expect(group).toBeVisible();
  const image=page.locator('.fms-preview-original.is-ready');
  await image.evaluate(el=>{window.zoomImage=el;});
  const fittedWidth=await page.locator('.fms-preview-visual').evaluate(el=>Math.round(parseFloat(el.style.width)));
  await expect.poll(async()=>Math.round((await image.boundingBox()).width)).toBe(fittedWidth);
  const fitted=await image.boundingBox();
  await group.getByRole('button',{name:'Actual size',exact:true}).click();
  await expect(group.locator('.fms-document-zoom-value')).toHaveText('100%');
  await expect.poll(async()=>Math.round((await image.boundingBox()).width)).toBe(2400);
  const visual=page.locator('.fms-preview-image-pan');
  const rect=await page.locator('.fms-preview-stage').boundingBox();
  await page.mouse.move(rect.x+rect.width/2,rect.y+rect.height/2);await page.mouse.down();await page.mouse.move(rect.x+rect.width/2-80,rect.y+rect.height/2-40,{steps:8});await page.mouse.up();
  await expect.poll(()=>visual.evaluate(el=>new DOMMatrix(getComputedStyle(el).transform).m41)).toBeLessThan(-50);
  await expect(page.locator('.fms-preview-dialog')).toBeVisible();
  await page.keyboard.press('Control+0');
  await expect.poll(async()=>Math.round((await image.boundingBox()).width)).toBe(Math.round(fitted.width));
  assert.equal(await image.evaluate(el=>el===window.zoomImage),true);
  const toggle=page.locator('.fms-preview-actionbar').getByRole('button',{name:'AI chats',exact:true});
  await expect(toggle.locator('svg.flex-character')).toHaveAttribute('data-active','false');
  await expect(page.locator('.fms-preview-header').getByRole('button',{name:'Comments',exact:true})).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('one dock survives preview close and Comments to AI keeps media geometry and the comment draft',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,comments:true,assistant:true});
 try{
  const dock=page.locator('.fms-preview-actionbar');
  await expect(dock.getByRole('button',{name:'Create',exact:true})).toBeVisible();
  await dock.evaluate(el=>{window.persistentDock=el;});
  await dock.getByRole('button',{name:'Search by name…',exact:true}).click();
  await expect(page.locator('.fms-header-search input')).toBeFocused();
  await dock.getByRole('button',{name:'Create',exact:true}).click();
  await expect(page.getByRole('dialog',{name:'Create',exact:true}).getByRole('button',{name:'Upload files',exact:true})).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  const actions=page.locator('.fms-preview-actionbar');
  await actions.getByRole('button',{name:'Comments',exact:true}).click();
  const input=page.getByRole('textbox',{name:'Add a comment',exact:true});
  await input.fill('Unsent comment draft');await input.evaluate(el=>{window.commentDraftNode=el;});
  const before=await page.locator('.fms-preview-stage').boundingBox();
  await actions.getByRole('button',{name:'AI chats',exact:true}).click();
  await expect(page.getByRole('complementary',{name:'AI assistant',exact:true})).toBeVisible();
  const after=await page.locator('.fms-preview-stage').boundingBox();
  assert.ok(Math.abs(before.width-after.width)<.5);assert.ok(Math.abs(before.x-after.x)<.5);
  await expect(input).toBeHidden();
  await actions.getByRole('button',{name:'Comments',exact:true}).click();
  await expect(input).toHaveText('Unsent comment draft');
  assert.equal(await input.evaluate(el=>el===window.commentDraftNode),true);
  await page.locator('.fms-preview-header').getByRole('button',{name:'Close',exact:true}).click();
  await expect(actions.getByRole('button',{name:'Create',exact:true})).toBeVisible();
  await expect(actions.getByRole('button',{name:'Download',exact:true})).toHaveCount(0);
  assert.equal(await actions.evaluate(el=>el===window.persistentDock),true);
  await expect(actions.getByRole('button',{name:'AI chats',exact:true})).toBeVisible();
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('dock stays opaque on every sampled closing frame while only its actions change',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700"><rect width="1000" height="700" fill="#eef2f5"/></svg>'}));
 try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await expect(page.locator('.fms-preview-original.is-ready')).toBeVisible();
  await page.locator('.fms-preview-actionbar').evaluate(bar=>{window.closeDock=bar;window.closeDockFrames=[];window.sampleDock=true;const tick=()=>{if(!window.sampleDock)return;let opacity=1;for(let el=bar;el instanceof HTMLElement;el=el.parentElement)opacity*=Number(getComputedStyle(el).opacity);const r=bar.getBoundingClientRect();window.closeDockFrames.push({connected:bar.isConnected,opacity,width:r.width,height:r.height,hidden:bar.hidden});requestAnimationFrame(tick);};requestAnimationFrame(tick);});
  await page.locator('.fms-preview-header').getByRole('button',{name:'Close',exact:true}).click();
  await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
  const frames=await page.evaluate(()=>{window.sampleDock=false;return window.closeDockFrames;});
  assert.ok(frames.length>8);assert.ok(frames.every(f=>f.connected&&!f.hidden&&f.opacity>.99&&f.width>50&&f.height>30),JSON.stringify(frames));
  assert.equal(await page.locator('.fms-preview-actionbar').evaluate(el=>el===window.closeDock),true);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('image camera animates zoom, anchors trackpad pinch, pans, resets and disposes its SDK handle',async()=>{
 const {page,errors}=await preview(1440,{imagePreview:{wheelBehavior:'pan'}}, {files:1,imageSDK:true,drawing:true});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="2400" height="1600"><rect width="2400" height="1600" fill="#eef2f5"/></svg>'}));
 try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>!!window.imageViewport?.getState().ready)).toBe(true);
  const fit=await page.evaluate(()=>window.imageViewport.getState().zoom);
  const plane=page.locator('.fms-preview-image-pan');
  const layoutWidth=await plane.evaluate(el=>el.parentElement.clientWidth);
  await page.evaluate(()=>{window.imageViewport.zoomTo(1);window.cameraIntermediate=[];const el=document.querySelector('media-studio')?.shadowRoot?.querySelector('.fms-preview-image-pan')??document.querySelector('.fms-preview-image-pan');window.cameraPlane=el;});
  const samples=await plane.evaluate(el=>new Promise(resolve=>{const values=[];const tick=()=>{values.push(new DOMMatrix(getComputedStyle(el).transform).a);if(values.length<24)requestAnimationFrame(tick);else resolve(values);};requestAnimationFrame(tick);}));
  assert.ok(samples.some(value=>value>1.001&&value<1/fit-.001),JSON.stringify(samples));
  assert.equal(await plane.evaluate(el=>el.parentElement.clientWidth),layoutWidth);
  await expect.poll(()=>plane.evaluate(el=>new DOMMatrix(getComputedStyle(el).transform).a)).toBeCloseTo(1/fit,2);
  const r=await page.locator('.fms-preview-picture').boundingBox(),point={x:r.x+r.width/2+90,y:r.y+r.height/2+20};
  await page.locator('.fms-preview-picture').evaluate((el,p)=>{el.dispatchEvent(new WheelEvent('wheel',{clientX:p.x,clientY:p.y,deltaY:-40,ctrlKey:true,bubbles:true,cancelable:true}));},point);
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeGreaterThan(1.1);
  const state=await page.evaluate(()=>window.imageViewport.getState());
  assert.ok(Math.abs(state.x-(90-90*state.zoom))<1);
  await page.locator('.fms-preview-picture').evaluate((el,p)=>el.dispatchEvent(new WheelEvent('wheel',{clientX:p.x,clientY:p.y,deltaX:40,deltaY:20,bubbles:true,cancelable:true})),point);
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().x)).toBeLessThan(state.x-20);
  await page.evaluate(()=>window.imageViewport.zoomTo('fit',{animate:false}));
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().fit)).toBe(true);
  const cdp=await page.context().newCDPSession(page);
  const cx=r.x+r.width/2,cy=r.y+r.height/2;
  await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{id:1,x:cx-60,y:cy},{id:2,x:cx+60,y:cy}]});
  await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{id:1,x:cx-100,y:cy},{id:2,x:cx+100,y:cy}]});
  await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeGreaterThan(fit*1.3);
  await expect(page.locator('.fms-preview-dialog')).toBeVisible();
  await cdp.detach();
  await page.evaluate(()=>window.previewController.setZoom('fit'));
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().fit)).toBe(true);
  assert.equal(await page.evaluate(()=>window.previewController.search('text')),false);
  assert.equal(await page.evaluate(()=>window.previewController.getEntry().id),'000');
  await page.locator('.fms-preview-header').getByRole('button',{name:'Rename file',exact:true}).click();
  await page.getByRole('textbox',{name:'Rename file',exact:true}).fill('Updated photograph');
  await page.getByRole('textbox',{name:'Rename file',exact:true}).press('Enter');
  await expect(page.locator('.fms-preview-header').getByRole('button',{name:'Rename file',exact:true})).toHaveText('Updated photograph.svg');
  assert.equal(await page.evaluate(()=>window.lastEntryUpdate.name),'Updated photograph.svg');
  await page.locator('.fms-preview-header').getByRole('button',{name:'Close',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport===null)).toBe(true);
  assert.equal(await page.evaluate(()=>window.lastImageViewport.getState().ready),false);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('photo zoom follows Fabric relative steps, intrinsic percentages, centering and default wheel zoom',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,imageSDK:true,intrinsicImage:true});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="500"><rect width="1000" height="500" fill="#eef2f5"/></svg>'}));
 try{
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>!!window.imageViewport?.getState().ready)).toBe(true);
  const frame=await page.locator('.fms-preview-picture').boundingBox();
  const initial=await page.evaluate(()=>window.imageViewport.getState());
  assert.ok(Math.abs(initial.zoom-Math.min(frame.width/4000,frame.height/2000)*.9)<.0001);
  assert.equal(initial.minZoom,initial.zoom);
  const group=page.locator('.fms-document-header-zoom');
  await expect(group.getByRole('button',{name:'Zoom out',exact:true})).toBeDisabled();
  await group.getByRole('button',{name:'Zoom in',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeCloseTo(initial.zoom*1.25,4);
  await group.getByRole('button',{name:'Zoom in',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeCloseTo(initial.zoom*1.25*1.25,4);
  await group.getByRole('button',{name:'Zoom out',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeCloseTo(initial.zoom*1.25*1.25*.75,4);
  await group.getByRole('button',{name:'Actual size',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBe(1);
  await expect.poll(()=>page.locator('.fms-preview-image-pan').evaluate(el=>el.getAnimations().length)).toBe(0);
  await page.evaluate(()=>window.imageViewport.panBy(70,30));
  await group.getByRole('button',{name:'Zoom in',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBe(1.25);
  assert.equal(await page.evaluate(()=>window.imageViewport.getState().x),0);
  await expect.poll(()=>page.locator('.fms-preview-image-pan').evaluate(el=>el.getAnimations().length)).toBe(0);
  await page.locator('.fms-preview-picture').evaluate(el=>{const r=el.getBoundingClientRect();el.dispatchEvent(new WheelEvent('wheel',{clientX:r.x+r.width/2,clientY:r.y+r.height/2,deltaY:-10,bubbles:true,cancelable:true}));});
  await expect.poll(()=>page.evaluate(()=>window.imageViewport.getState().zoom)).toBeGreaterThan(1.25);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('zoom after a grid thumbnail flight paints outside its fitted image rectangle',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,imageSDK:true});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1600"><rect width="1600" height="1600" fill="#2a91c8"/></svg>'}));
 try{
  const thumbnail=page.locator('[data-preview-entry="000"] img').first();
  await expect.poll(()=>thumbnail.evaluate(el=>el.complete&&el.naturalWidth>0)).toBe(true);
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>!!window.imageViewport?.getState().ready)).toBe(true);
  const box=page.locator('.fms-preview-visual');
  await expect.poll(()=>box.evaluate(el=>getComputedStyle(el).clipPath)).toBe('none');
  const fitted=await box.boundingBox();
  await page.evaluate(()=>window.imageViewport.zoomTo(1,{animate:false}));
  const image=page.locator('.fms-preview-original.is-ready');
  await expect.poll(async()=>Math.round((await image.boundingBox()).width)).toBe(1600);
  const point={x:fitted.x-24,y:fitted.y+fitted.height/2};
  const visible=await box.evaluate((el,p)=>{const root=el.getRootNode();const hits=root.elementsFromPoint(p.x,p.y);const plane=el.querySelector('.fms-preview-image-pan');return {hit:hits.includes(plane),clips:[el,el.parentElement].map(n=>getComputedStyle(n).clipPath)};},point);
  assert.equal(visible.hit,true,JSON.stringify(visible));
  assert.deepEqual(visible.clips,['none','none']);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('closing a zoomed panned image freezes its painted camera without jumping to the fitted box',async()=>{
 const {page,errors}=await preview(1440,{}, {files:1,imageSDK:true});
 await page.route('**/original/*',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="1600" height="1000" fill="#2a91c8"/></svg>'}));
 try{
  for(const interrupted of [false,true]){
  await page.getByRole('button',{name:'Preview: Photo 000.svg',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>!!window.imageViewport?.getState().ready)).toBe(true);
  await page.evaluate(()=>{window.imageViewport.zoomTo(1.5,{animate:false});window.imageViewport.panBy(-160,65);});
  if(interrupted){await page.evaluate(()=>window.imageViewport.zoomTo(2));await page.locator('.fms-preview-image-pan').evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));}
  const geometry=await page.locator('.fms-preview-image-pan').evaluate(plane=>{
   const before=plane.getBoundingClientRect().toJSON();const viewport=plane.closest('.fms-preview-pages').getBoundingClientRect().toJSON();
   window.previewController.close();
   const clone=plane.getRootNode().querySelector('.fms-preview-return');
   return {before,viewport,after:clone.querySelector('.fms-preview-image-pan').getBoundingClientRect().toJSON(),clip:getComputedStyle(clone).clipPath};
  });
  for(const key of ['x','y','width','height'])assert.ok(Math.abs(geometry.before[key]-geometry.after[key])<1,JSON.stringify(geometry));
  assert.notEqual(geometry.clip,'inset(0%)');
  await expect(page.locator('.fms-preview-dialog')).toHaveCount(0);
  await expect(page.locator('.fms-preview-return')).toHaveCount(0);
  }
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('dock Create opens an animated full-screen veil with existing actions and restores focus',async()=>{
 for(const width of [390,1440]){
  const {page,errors}=await preview(width,{}, {files:1});
  try{
   const trigger=page.locator('.fms-preview-actionbar').getByRole('button',{name:'Create',exact:true});
   await trigger.click();
   const dialog=page.getByRole('dialog',{name:'Create',exact:true});
   await expect(dialog).toBeVisible();
   await expect(dialog.getByRole('button',{name:'Upload files',exact:true})).toBeVisible();
   await expect(dialog.getByRole('button',{name:'New folder',exact:true})).toBeVisible();
   await expect(page.locator('.fms-create-overlay-backdrop')).toHaveCSS('backdrop-filter','blur(20px)');
   const bounds=await page.locator('.fms-create-overlay-backdrop').boundingBox();
   assert.equal(Math.round(bounds.width),width);assert.equal(Math.round(bounds.height),900);
   const position=await dialog.boundingBox();assert.ok(position.y>450);assert.ok(Math.abs(position.x+position.width/2-width/2)<1);
   await expect(page.locator('.fms-create-overlay-backdrop')).toHaveCSS('background-color','rgba(255, 255, 255, 0.88)');
   const tiles=await dialog.locator('.fms-create-overlay-action').evaluateAll(elements=>elements.map(el=>el.getBoundingClientRect().toJSON()));
   assert.ok(tiles.every(r=>r.left>=0&&r.right<=width));
   // Sample the rendered geometry throughout dismissal, not just its endpoints.
   await dialog.evaluate(async el=>{await Promise.all(el.getAnimations({subtree:true}).map(a=>a.finished.catch(()=>{})));});
   const closingFrames=await dialog.evaluate(async el=>{
    const rows=[...el.querySelectorAll('.fms-create-overlay-action')];
    const snapshot=()=>rows.map(el=>{const r=el.getBoundingClientRect();return [r.x,r.y,r.width,r.height];});
    const initial=snapshot();
    el.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',code:'Escape',bubbles:true}));
    const frames=[];
    while(rows[0]?.isConnected&&frames.length<45){
     await new Promise(requestAnimationFrame);
     if(rows[0].isConnected)frames.push(snapshot());
    }
    return {initial,frames,removed:!rows[0]?.isConnected};
   });
   assert.ok(closingFrames.removed,'dismissal completes');
   assert.ok(closingFrames.frames.length>=2,'sample multiple exit frames');
   for(const frame of closingFrames.frames)for(let row=0;row<frame.length;row++)for(let axis=0;axis<4;axis++){
    assert.ok(Math.abs(frame[row][axis]-closingFrames.initial[row][axis])<.5,'row geometry remains fixed while fading');
   }
   await expect(dialog).toHaveCount(0);await expect(trigger).toBeFocused();
   await trigger.click();await page.mouse.click(20,20);await expect(dialog).toHaveCount(0);
   await expect(trigger).toBeFocused();
   await trigger.click();await dialog.getByRole('button',{name:'New folder',exact:true}).click();
   await expect(page.getByRole('dialog',{name:'Create new folder',exact:true})).toBeVisible();
   await expect(dialog).toHaveCount(0);
   await page.keyboard.press('Escape');
   await expect(page.getByRole('dialog',{name:'Create new folder',exact:true})).toHaveCount(0);
   assert.deepEqual(errors,[]);
  }finally{await page.close();}
 }
});

test('library folder creation shares the Move form and covers an open assistant',async()=>{
 const {page,errors}=await preview(1440,{}, {assistant:true,files:1});
 try{
  await page.getByRole('navigation',{name:'Main navigation',exact:true}).getByRole('button',{name:'AI chats',exact:true}).click();
  await page.getByRole('button',{name:'New AI Chat',exact:true}).click();
  await expect(page.getByRole('complementary',{name:'AI assistant',exact:true})).toBeVisible();
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'Create',exact:true}).click();
  await page.getByRole('dialog',{name:'Create',exact:true}).getByRole('button',{name:'New folder',exact:true}).click();
  const dialog=page.getByRole('dialog',{name:'Create new folder',exact:true});
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.fms-create-destination-path')).toHaveText('Spaces');
  await expect(dialog.getByRole('button',{name:/^Cancel/})).toBeVisible();
  await expect(dialog.getByRole('button',{name:/^Save/})).toBeDisabled();
  const cover=await dialog.evaluate(el=>{
   const root=el.getRootNode(),backdrop=root.querySelector('.fms-create-destination-backdrop');
   const chat=root.querySelector('.fms-assistant'),b=backdrop.getBoundingClientRect(),c=chat.getBoundingClientRect();
   const hit=root.elementFromPoint(c.right-20,c.top+c.height/2);
   return {left:b.left,right:b.right,chatRight:c.right,hit:hit===backdrop||backdrop.contains(hit),z:Number(getComputedStyle(backdrop).zIndex)};
  });
  assert.equal(cover.left,0);assert.ok(cover.right>=cover.chatRight);assert.ok(cover.z>60);assert.ok(cover.hit,'modal intercepts interaction above the assistant');
  await dialog.getByRole('textbox',{name:'Name',exact:true}).fill('Folder example');
  await expect(dialog.getByRole('button',{name:/^Save/})).toBeEnabled();
  await dialog.getByRole('button',{name:/^Cancel/}).click();await expect(dialog).toHaveCount(0);
  await expect(page.getByRole('complementary',{name:'AI assistant',exact:true})).toBeVisible();
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('library controls collapse by available space and selection spans the content column',async()=>{
 const {page,errors}=await preview(1800,{}, {files:4,organize:true,controls:true,responsiveControls:true});
 try{
  const filters=page.locator('.fms-responsive-filters');
  await expect(filters).not.toHaveAttribute('data-compact');
  await expect(page.locator('.fms-creation-expanded')).toBeVisible();
  await page.getByRole('button',{name:'Select loaded',exact:true}).click();
  const selection=page.locator('.fms-selection-bar');
  const full=await selection.boundingBox(),main=await page.locator('.fms-main').boundingBox();
  assert.ok(Math.abs(full.width-main.width)<1);assert.ok(Math.abs(full.x-main.x)<1);
  await expect(selection.locator('[data-slot=button-group]')).toHaveCount(1);
  const sharedGutter=await page.locator('.fms-main').evaluate(el=>{
   return ['.fms-header','.fms-title-row','.fms-toolbar','.fms-selection-bar'].map(selector=>{
    const style=getComputedStyle(el.querySelector(selector));
    return [style.paddingInlineStart,style.paddingInlineEnd];
   });
  });
  assert.deepEqual(sharedGutter,Array.from({length:4},()=>['12px','12px']));
  await expect(selection.locator('.fms-selection-action-label').first()).toBeVisible();
  for(const width of [960,760,390]){
   await page.setViewportSize({width,height:900});
   await expect(filters).toHaveAttribute('data-compact','true');
   if(width<=760){await expect(page.locator('.fms-creation-compact')).toBeVisible();await expect(page.locator('.fms-creation-expanded')).toBeHidden();}
   const toolbar=await page.locator('.fms-toolbar').boundingBox();
   const controls=await page.locator('.fms-toolbar button:visible').evaluateAll(nodes=>nodes.map(el=>el.getBoundingClientRect().toJSON()));
   assert.ok(controls.every(r=>r.left>=toolbar.x-1&&r.right<=toolbar.x+toolbar.width+1),'toolbar controls stay inside the column');
   const bar=await selection.boundingBox(),column=await page.locator('.fms-main').boundingBox();
   assert.ok(Math.abs(bar.width-column.width)<1);
   await expect(selection.locator('.fms-selection-action-label').first()).toBeHidden();
   const buttons=await selection.locator('button').evaluateAll(nodes=>nodes.map(el=>el.getBoundingClientRect().toJSON()));
   assert.ok(buttons.every(r=>r.left>=bar.x&&r.right<=bar.x+bar.width),'selection actions do not overflow');
  }
  await filters.getByRole('button',{name:'Filters',exact:true}).click();
  const hub=page.locator('.fms-filter-hub');await expect(hub).toBeVisible();
  for(const label of ['Type','Extension','Tags','Tag language','Date & size'])await expect(hub.getByRole('button',{name:label,exact:true})).toBeVisible();
  await hub.getByRole('button',{name:'Type',exact:true}).click();
  const dimensions=await page.locator('.fms-choice-surface').evaluate(el=>{const list=el.querySelector('[role=listbox]'),row=list.querySelector('[role=option]');return {gap:el.getBoundingClientRect().right-list.getBoundingClientRect().right,row:row.getBoundingClientRect().height,scrollable:list.scrollHeight>list.clientHeight};});
  assert.ok(dimensions.gap<=1,'scrollbar reaches the surface edge');assert.ok(dimensions.row<=32);assert.ok(dimensions.scrollable);
  await page.keyboard.press('Escape');await expect(page.locator('.fms-choice-surface')).toHaveCount(0);
  await hub.getByRole('button',{name:'Tags',exact:true}).click();
  await page.getByRole('option',{name:'Campaign',exact:true}).click();await page.keyboard.press('Escape');await expect(page.locator('.fms-choice-surface')).toHaveCount(0);
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.filters?.tagIds)).toEqual(['tag']);
  await page.keyboard.press('Escape');
  await expect(hub).toHaveCount(0);
  await page.locator('.fms-creation-compact').getByRole('button',{name:'Create',exact:true}).click();
  await expect(page.getByRole('menuitem',{name:'Upload files',exact:true})).toBeVisible();
  await expect(page.getByRole('menuitem',{name:'New folder',exact:true})).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Select loaded',exact:true}).click();
  await selection.getByRole('button',{name:'Move',exact:true}).hover();await expect(page.getByRole('tooltip',{name:'Move',exact:true})).toBeVisible();
  await page.setViewportSize({width:1800,height:900});await expect(filters).not.toHaveAttribute('data-compact');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('applying and clearing compact filters retain the header and toolbar through delayed results',async()=>{
 const {page,errors}=await preview(960,{}, {files:4,organize:true,controls:true,responsiveControls:true,browseDelay:180});
 try{
  await expect(page.getByRole('button',{name:'Select loaded',exact:true})).toBeVisible();
  await page.locator('.fms-responsive-filters').getByRole('button',{name:'Filters',exact:true}).click();
  const hub=page.locator('.fms-filter-hub');await hub.getByRole('button',{name:'Date & size',exact:true}).click();
  const date=page.getByRole('dialog',{name:'Date & size',exact:true});
  await date.getByLabel('Minimum size (MiB)').fill('2');
  await page.locator('.fms-toolbar').evaluate(el=>{
   const root=el.getRootNode(),selectors=['.fms-title-row','.fms-toolbar','.fms-toolbar-view','.fms-library-actions','.fms-filter-hub'];
   window.stableFilterNodes=selectors.map(selector=>{const node=root.querySelector(selector);return {selector,node,rect:node.getBoundingClientRect().toJSON()};});
   window.filterLayoutSamples=[];window.sampleFilters=true;
   const sample=()=>{if(!window.sampleFilters)return;window.filterLayoutSamples.push(window.stableFilterNodes.map(({node})=>({connected:node.isConnected,rect:node.getBoundingClientRect().toJSON()})));requestAnimationFrame(sample);};requestAnimationFrame(sample);
  });
  await date.getByRole('button',{name:'Apply filters',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.filters?.minBytes)).toBe(2097152);
  await expect(date).toHaveCount(0);
  await hub.getByRole('button',{name:'Date & size',exact:true}).click();
  await date.getByRole('button',{name:'Clear filters',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.filters)).toBeUndefined();
  await expect(date).toHaveCount(0);
  const result=await page.evaluate(()=>{window.sampleFilters=false;return {nodes:window.stableFilterNodes.map(({node,rect})=>({connected:node.isConnected,initial:rect,final:node.getBoundingClientRect().toJSON()})),frames:window.filterLayoutSamples};});
  assert.ok(result.frames.length>4);
  for(const frame of result.frames)for(let i=0;i<frame.length;i++){
   assert.ok(frame[i].connected,'control nodes remain mounted');
   for(const axis of ['x','y','width','height'])assert.ok(Math.abs(frame[i].rect[axis]-result.nodes[i].initial[axis])<1,`filter/header geometry remains stable during loading: ${i} ${axis} ${frame[i].rect[axis]} vs ${result.nodes[i].initial[axis]}`);
  }
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('library search has one outline and animates filters without replacing virtual results',async()=>{
 const {page,errors}=await preview(1120,{}, {files:100,organize:true,librarySearch:true});
 try{
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'Search by name…',exact:true}).click();
  const search=page.getByRole('searchbox',{name:'Search library',exact:true});
  await search.fill('Photo');
  const panel=page.getByRole('region',{name:'Search results',exact:true});
  const list=panel.getByRole('listbox');
  await expect(list.getByRole('option',{name:/Photo 000/})).toBeVisible();
  const toggle=page.locator('.fms-search-filters-toggle');
  const borders=await panel.evaluate(el=>{
   const field=el.parentElement.querySelector('[data-slot=search-field-group]');
   return [getComputedStyle(el).borderLeftWidth,getComputedStyle(el,'::before').borderLeftWidth,getComputedStyle(field).boxShadow];
  });
  assert.deepEqual(borders,['0px','1px','none']);
  await expect(panel).toHaveCSS('border-top-width','1px');
  assert.equal(await panel.evaluate(el=>getComputedStyle(el,'::before').display),'none');
  const track=async()=>{
   await panel.locator('.fms-search-filter-disclosure').evaluate(el=>{
    window.searchFilterFrames=[];
    const observer=new ResizeObserver(()=>window.searchFilterFrames.push(el.getBoundingClientRect().height));
    observer.observe(el);
    window.stopSearchFilterFrames=()=>observer.disconnect();
   });
   await toggle.click();
   await panel.locator('.fms-search-filter-disclosure').evaluate(async el=>{await Promise.all(el.getAnimations().map(animation=>animation.finished));});
   return page.evaluate(()=>{window.stopSearchFilterFrames();return window.searchFilterFrames;});
  };
  const opening=await track();
  await expect(panel.getByRole('button',{name:'File types',exact:true})).toBeVisible();
  const closing=await track();
  const end=Math.max(...opening);
  assert.ok(end>=38&&opening.some(height=>height>2&&height<end-2),JSON.stringify(opening));
  assert.ok(closing.some(height=>height>2&&height<end-2)&&closing.at(-1)<1,JSON.stringify(closing));
  await expect(panel.getByRole('button',{name:'File types',exact:true})).toHaveCount(0);
  await expect(search).toHaveValue('Photo');
  await list.evaluate(el=>{el.scrollTop=el.scrollHeight;},{},{timeout:5000});
  await expect.poll(()=>page.evaluate(()=>window.librarySearchRequests.some(request=>request.cursor==='24'))).toBe(true);
  assert.ok(await list.getByRole('option').count()<48);
  await search.press('Escape');
  await expect(panel).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('library search fits content, keeps its anchor through Show more and preserves native filter controls',async()=>{
 const {page,errors}=await preview(1120,{}, {files:100,organize:true,librarySearch:true,slowSearch:true});
 try{
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'Search by name…',exact:true}).click();
  const search=page.getByRole('searchbox',{name:'Search library',exact:true});
  await search.click();
  const panel=page.getByRole('region',{name:'Search results',exact:true});
  const more=panel.getByRole('button',{name:'Show more',exact:true});
  await expect(more).toBeVisible();
  await panel.evaluate(async el=>{await Promise.all(el.closest('[role=dialog]').parentElement.getAnimations().map(animation=>animation.finished));});
  const before=await panel.boundingBox();
  const assertAnchor=async()=>{
   const bounds=await panel.boundingBox();
   assert.deepEqual({x:bounds.x,y:bounds.y,width:bounds.width},{x:before.x,y:before.y,width:before.width});
   return bounds.height;
  };
  await more.click();
  await expect(panel.locator('.fms-search-status[role=status]')).toBeVisible();
  assert.ok(Math.abs(await assertAnchor()-before.height)<1,'Show more preserves the current height while fetching');
  await expect(panel.getByRole('option',{name:/Photo 000/})).toBeVisible();
  const fullHeight=await assertAnchor();
  assert.ok(fullHeight>before.height&&fullHeight<=560);
  await search.fill('Photo 001');
  await expect(panel.getByRole('option',{name:/Photo 001/})).toBeVisible();
  assert.ok(await assertAnchor()<fullHeight,'a single match keeps the panel compact');
  await search.fill('no-such-file');
  await expect(panel.locator('.fms-search-empty')).toContainText('No exact matches');
  await expect(panel.locator('.fms-search-empty')).toContainText('Try a different name or adjust your filters.');
  assert.ok(await assertAnchor()<fullHeight);
  await search.press('Escape');
  await expect(page.getByRole('dialog',{name:'Search library',exact:true})).toBeHidden();
  await page.locator('.fms-preview-actionbar').getByRole('button',{name:'Search by name…',exact:true}).click();
  await expect(page.locator('.fms-search-primary [data-slot=search-field-group]')).toHaveCSS('background-color',/^(rgb\(255, 255, 255\)|oklch\(1 0 0\))$/);
  await page.locator('.fms-search-filters-toggle').click();
  await panel.getByRole('button',{name:'Workspaces',exact:true}).click();
  const selected=page.getByRole('menuitemradio',{name:'Brand assets',exact:true});
  const aligned=await selected.evaluate(el=>{
   const a=el.getBoundingClientRect(),label=el.querySelector('[data-slot=label]').getBoundingClientRect(),check=el.querySelector('.menu-item__indicator').getBoundingClientRect();
   return check.left>label.right&&Math.abs(check.top+check.height/2-a.top-a.height/2)<1;
  });
  assert.ok(aligned,'workspace checkmark is aligned on the right');
  await page.keyboard.press('Escape');
  await panel.getByRole('button',{name:'Uploaded by',exact:true}).click();
  const uploaders=page.getByRole('dialog',{name:'Uploaded by',exact:true});
  const memberField=uploaders.getByRole('searchbox');
  const style=await memberField.evaluate(el=>{
   const group=el.closest('[data-slot=search-field-group]');
   return {background:getComputedStyle(group).backgroundColor,radius:getComputedStyle(group).borderRadius};
  });
  assert.notEqual(style.background,'rgba(0, 0, 0, 0)');
  assert.notEqual(style.radius,'16px 16px 0px 0px');
  await memberField.fill('Ada');
  await expect(uploaders.getByRole('option',{name:'Ada Nowak',exact:true})).toBeVisible();
  await expect.poll(()=>page.evaluate(()=>window.uploaderRequests.some(request=>request.search==='Ada'&&request.workspace==='one'))).toBe(true);
  await uploaders.getByRole('option',{name:'Ada Nowak',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.librarySearchRequests.at(-1).query.filters.uploaderIds)).toEqual(['999']);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`workspace invitation table and nested add modal spacing at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {workspaceIdentity:true,workspaceSettings:true,workspaceAccess:true});
 try{
  await page.getByRole('button',{name:'Account menu',exact:true}).click();
  await page.getByRole('menuitem',{name:'Workspaces · Settings',exact:true}).click();
  const settings=page.getByRole('dialog',{name:'Workspace settings',exact:true});
  await settings.getByRole('tab',{name:'People & access',exact:true}).click();
  await expect(settings.getByRole('heading',{name:'Team invitations',exact:true})).toBeVisible();
  await expect(settings.getByText('invited@example.test',{exact:true})).toBeVisible();
  assert.equal(await settings.locator('details.fms-workspace-invitations').count(),0);
  await settings.getByRole('button',{name:'Add people',exact:true}).click();
  const add=page.getByRole('dialog',{name:'Add people',exact:true});
  await expect(add).toBeVisible();
  const padding=await add.locator('.modal__body').evaluate(el=>({left:getComputedStyle(el).paddingLeft,right:getComputedStyle(el).paddingRight}));
  assert.deepEqual(padding,{left:'0px',right:'0px'});
  await add.getByRole('button',{name:'Close',exact:true}).last().click();
  await expect(settings.getByRole('tab',{name:'People & access',exact:true})).toBeVisible();
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`workspace members modal reuses access tables and nested add dialog at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {workspaceIdentity:true,workspaceAccess:true});
 try{
  await page.getByRole('button',{name:'Workspace members',exact:true}).click();
  const members=page.getByRole('dialog',{name:'Workspace members',exact:true});
  await expect(members.getByText('Ada Nowak',{exact:true})).toBeVisible();
  await expect(members.getByRole('heading',{name:'Team invitations',exact:true})).toBeVisible();
  await expect(members.getByText('invited@example.test',{exact:true})).toBeVisible();
  await members.getByRole('button',{name:'Add people',exact:true}).click();
  const add=page.getByRole('dialog',{name:'Add people',exact:true});
  await expect(add).toBeVisible();
  assert.equal(await add.locator('.modal__body').evaluate(el=>getComputedStyle(el).paddingLeft),'0px');
  await add.getByRole('button',{name:'Close',exact:true}).last().click();
  await members.getByRole('button',{name:'Close',exact:true}).last().click();
  await expect(members).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`layout and sort native choice alignment at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {files:60,controls:true});
 try{
  await page.getByRole('button',{name:'Layout',exact:true}).click();
  const list=page.getByRole('menu',{name:'Layout',exact:true});
  await expect(list).toBeVisible();
  const geometry=await list.getByRole('menuitemradio',{name:'Grid view',exact:true}).evaluate(row=>{const icon=row.querySelector(':scope>svg').getBoundingClientRect(),label=row.querySelector('.label').getBoundingClientRect(),check=row.querySelector('[data-slot=menu-item-indicator]').getBoundingClientRect();return {iconRight:icon.right,labelLeft:label.left,labelRight:label.right,checkLeft:check.left,centerDifference:Math.abs(check.y+check.height/2-label.y-label.height/2)};});
  assert.ok(geometry.iconRight<=geometry.labelLeft);assert.ok(geometry.labelRight<=geometry.checkLeft);assert.ok(geometry.centerDifference<1.5);
  await list.getByRole('menuitemradio',{name:'List view',exact:true}).click();await expect(list).toHaveCount(0);
  await page.getByRole('button',{name:'Sort by: Name, Ascending',exact:true}).click();
  const sort=page.getByRole('menu',{name:/^Sort by/});
  const sortGeometry=await sort.getByRole('menuitemradio',{name:/^Name/}).evaluate(row=>{const label=row.querySelector('.label').getBoundingClientRect(),check=row.querySelector('[data-slot=menu-item-indicator]').getBoundingClientRect();return {labelRight:label.right,checkLeft:check.left,diff:Math.abs(check.y+check.height/2-label.y-label.height/2)};});
  assert.ok(sortGeometry.labelRight<=sortGeometry.checkLeft);assert.ok(sortGeometry.diff<1.5);
  await sort.getByRole('menuitemradio',{name:'File size',exact:true}).click();await expect(sort).toHaveCount(0);
  await expect(page.getByRole('button',{name:'Sort by: File size, Ascending',exact:true})).toBeVisible();
  await page.getByRole('button',{name:'Sort by: File size, Ascending',exact:true}).click();
  await expect(page.getByRole('menuitemradio',{name:'Ascending',exact:true})).toHaveAttribute('aria-checked','true');
  await page.getByRole('menuitemradio',{name:'Descending',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.direction)).toBe('desc');
  await page.getByRole('button',{name:'Sort by: File size, Descending',exact:true}).click();
  await expect(page.getByRole('menuitemradio',{name:'Descending',exact:true})).toHaveAttribute('aria-checked','true');
  await page.getByRole('menuitemradio',{name:'Ascending',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.direction)).toBe('asc');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`library title scrolls while filters remain sticky at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {files:120,controls:true,workspaceAccess:true});
 try{
  const viewport=page.locator('.fms-main.is-library-scroll'),title=page.locator('.fms-title-row'),toolbar=page.locator('.fms-toolbar');
  const first=page.locator('.fms-entry').first();await expect(first).toBeVisible();
  const top=await viewport.evaluate(el=>el.getBoundingClientRect().top);
  const start=await title.evaluate(el=>el.getBoundingClientRect().top);
  const headerStart=await page.locator('.fms-header').evaluate(el=>el.getBoundingClientRect().top);
  await viewport.evaluate(el=>{el.scrollTop=400;});
  await expect.poll(()=>title.evaluate(el=>el.getBoundingClientRect().top)).toBeLessThan(start-300);
  await expect.poll(()=>toolbar.evaluate((el,top)=>Math.abs(el.getBoundingClientRect().top-top),top)).toBeLessThan(1.5);
  assert.equal(await toolbar.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
  await expect.poll(()=>page.locator('.fms-header').evaluate(el=>el.getBoundingClientRect().top)).toBeLessThan(headerStart-300);
  assert.ok(await page.locator('.fms-entry').count()<60);
  const dock=page.getByRole('toolbar',{name:'File actions',exact:true});await expect(dock).toBeVisible();
  const dockBox=await dock.boundingBox();assert.ok(dockBox.y>=top&&dockBox.y+dockBox.height<=900);
  await viewport.evaluate(el=>{el.scrollTop=0;});await expect.poll(()=>title.evaluate(el=>el.getBoundingClientRect().top)).toBe(start);
  await page.getByRole('button',{name:'Workspace members',exact:true}).click();await expect(page.getByRole('dialog',{name:'Workspace members',exact:true})).toBeVisible();
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const width of [390,1440])test(`library toolbar has balanced gutters and back only navigates a parent at ${width}`,async()=>{
 const {page,errors}=await preview(width,{}, {files:4,controls:true,organize:true});
 try{
  const header=page.locator('.fms-header');
  await expect(header.getByRole('button',{name:'Back',exact:true})).toHaveCount(0);
  const padding=await page.locator('.fms-toolbar').evaluate(el=>({top:getComputedStyle(el).paddingTop,bottom:getComputedStyle(el).paddingBottom}));
  assert.equal(padding.top,padding.bottom);assert.notEqual(padding.top,'0px');
  const gaps=await page.locator('.fms-toolbar').evaluate(el=>{const bar=el.getBoundingClientRect(),button=el.querySelector('button').getBoundingClientRect();return {top:button.top-bar.top,bottom:bar.bottom-button.bottom};});assert.ok(gaps.top>=9);assert.ok(Math.abs(gaps.top-gaps.bottom)<=1);
  await page.getByRole('button',{name:'Folder: Archive',exact:true}).click();
  await expect(header.getByRole('button',{name:'Back',exact:true})).toBeVisible();
  await header.getByRole('button',{name:'Back',exact:true}).click();
  await expect.poll(()=>page.evaluate(()=>window.testRequests.at(-1).query.parent)).toBe(null);
  await expect(header.getByRole('button',{name:'Back',exact:true})).toHaveCount(0);
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

for(const files of [4,60])test(`library action dock stays at the panel bottom with ${files} files`,async()=>{
 const {page,errors}=await preview(1440,{}, {files,controls:true});
 try{
  const main=page.locator('.fms-main'),dock=page.getByRole('toolbar',{name:'File actions',exact:true});
  await expect(dock).toBeVisible();
  const gap=()=>dock.evaluate(el=>el.closest('.fms-main').getBoundingClientRect().bottom-el.getBoundingClientRect().bottom);
  await expect.poll(gap).toBe(14);
  if(files>4){await main.evaluate(el=>{el.scrollTop=400;});await expect.poll(gap).toBe(14);}
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});


test('workspace header shows at most three avatars followed by the grey more control',async()=>{
 const {page,errors}=await preview(1440,{}, {workspaceAccess:true,memberCount:10});
 try{
  const stack=page.locator('.fms-member-stack');
  await expect(stack.locator('.fms-member-avatar')).toHaveCount(3);
  await expect(stack.locator('.fms-member-extra svg')).toHaveCount(1);
  await expect(stack.locator('.fms-member-extra')).toHaveCSS('background-color','rgb(236, 238, 241)');
  await stack.click();
  await expect(page.getByRole('dialog',{name:'Workspace members',exact:true})).toBeVisible();
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
